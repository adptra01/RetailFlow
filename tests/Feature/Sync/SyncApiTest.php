<?php

namespace Tests\Feature\Sync;

use App\Enums\SyncInboxStatus;
use App\Models\Device;
use App\Models\SyncInbox;
use App\Models\Tenant;
use App\Support\Devices\DeviceToken;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Sprint 4 item 3 — API push/pull (§11.3, §18).
 */
class SyncApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{0: Device, 1: string} perangkat + token plaintext
     */
    private function device(Tenant $tenant, array $overrides = []): array
    {
        $token = DeviceToken::generate();

        $device = Device::factory()->create(array_merge([
            'tenant_id' => $tenant->id,
            'token_hash' => $token->hash,
            'token_prefix' => $token->prefix,
        ], $overrides));

        return [$device, $token->plaintext];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function operation(array $overrides = []): array
    {
        return array_merge([
            'operation_id' => (string) Str::uuid(),
            'entity_type' => 'sale',
            'entity_id' => (string) Str::uuid(),
            'operation_type' => 'create',
            'payload' => ['total_minor' => 15000],
            'local_sequence' => 1,
        ], $overrides);
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    // ---------------------------------------------------------------- push

    public function test_push_requires_device_token(): void
    {
        $this->postJson('/api/v1/sync/push', ['operations' => [$this->operation()]])
            ->assertStatus(401)
            ->assertJson(['message' => 'Token perangkat tidak diberikan.']);
    }

    public function test_push_rejects_unknown_token(): void
    {
        $this->postJson('/api/v1/sync/push', ['operations' => [$this->operation()]], $this->bearer('rf_tidakdikenal'))
            ->assertStatus(401)
            ->assertJson(['message' => 'Perangkat tidak dikenal atau telah dicabut.']);
    }

    public function test_push_rejects_revoked_device(): void
    {
        $tenant = Tenant::factory()->create();
        [$device, $token] = $this->device($tenant, ['revoked_at' => now()]);

        $this->postJson('/api/v1/sync/push', ['operations' => [$this->operation()]], $this->bearer($token))
            ->assertStatus(401);

        $this->assertSame(0, SyncInbox::query()->count());
    }

    public function test_push_stores_operations_for_authenticated_device(): void
    {
        $tenant = Tenant::factory()->create();
        [$device, $token] = $this->device($tenant);
        $op = $this->operation(['local_sequence' => 7]);

        $response = $this->postJson('/api/v1/sync/push', ['operations' => [$op]], $this->bearer($token));

        $response->assertOk()
            ->assertJson([
                'accepted' => 1,
                'duplicates' => 0,
                'device_id' => $device->id,
                'tenant_id' => $tenant->id,
            ])
            ->assertJsonPath('results.0.operation_id', $op['operation_id'])
            ->assertJsonPath('results.0.status', 'received');

        $entry = SyncInbox::query()->where('operation_id', $op['operation_id'])->firstOrFail();
        $this->assertSame($tenant->id, $entry->tenant_id);
        $this->assertSame($device->id, $entry->device_id);
        $this->assertSame('sale', $entry->entity_type);
        $this->assertSame('create', $entry->operation_type);
        $this->assertSame(7, $entry->local_sequence);
        $this->assertSame(SyncInboxStatus::Received, $entry->status);
        $this->assertSame(['total_minor' => 15000], $entry->payload);
    }

    public function test_push_uses_authenticated_identity_over_payload_identity(): void
    {
        $tenant = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        [$device, $token] = $this->device($tenant);

        // Payload mencoba memalsukan tenant_id/device_id.
        $op = $this->operation(['payload' => ['tenant_id' => $other->id, 'device_id' => 'palsu']]);

        $this->postJson('/api/v1/sync/push', ['operations' => [$op]], $this->bearer($token))->assertOk();

        $entry = SyncInbox::query()->where('operation_id', $op['operation_id'])->firstOrFail();
        $this->assertSame($tenant->id, $entry->tenant_id);
        $this->assertSame($device->id, $entry->device_id);
    }

    public function test_push_is_idempotent_on_repeated_operation_id(): void
    {
        $tenant = Tenant::factory()->create();
        [, $token] = $this->device($tenant);
        $op = $this->operation();

        $this->postJson('/api/v1/sync/push', ['operations' => [$op]], $this->bearer($token))
            ->assertOk()
            ->assertJson(['accepted' => 1, 'duplicates' => 0]);

        $this->postJson('/api/v1/sync/push', ['operations' => [$op]], $this->bearer($token))
            ->assertOk()
            ->assertJson(['accepted' => 0, 'duplicates' => 1])
            ->assertJsonPath('results.0.status', 'duplicate');

        $this->assertSame(1, SyncInbox::query()->count());
    }

    public function test_push_validates_batch_shape(): void
    {
        $tenant = Tenant::factory()->create();
        [, $token] = $this->device($tenant);
        $headers = $this->bearer($token);

        $this->postJson('/api/v1/sync/push', [], $headers)->assertStatus(422)->assertJsonValidationErrors('operations');

        $this->postJson('/api/v1/sync/push', ['operations' => []], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('operations');

        $this->postJson('/api/v1/sync/push', [
            'operations' => [$this->operation(['operation_type' => 'meledak'])],
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('operations.0.operation_type');

        $this->postJson('/api/v1/sync/push', [
            'operations' => [$this->operation(['operation_id' => 'bukan-uuid'])],
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('operations.0.operation_id');
    }

    public function test_push_rejects_duplicate_operation_id_within_batch(): void
    {
        $tenant = Tenant::factory()->create();
        [, $token] = $this->device($tenant);
        $id = (string) Str::uuid();

        $this->postJson('/api/v1/sync/push', [
            'operations' => [
                $this->operation(['operation_id' => $id, 'local_sequence' => 1]),
                $this->operation(['operation_id' => $id, 'local_sequence' => 2]),
            ],
        ], $this->bearer($token))->assertStatus(422)->assertJsonValidationErrors('operations.0.operation_id');
    }

    public function test_push_enforces_max_batch_size(): void
    {
        config(['pos.sync.max_batch' => 2]);

        $tenant = Tenant::factory()->create();
        [, $token] = $this->device($tenant);

        $this->postJson('/api/v1/sync/push', [
            'operations' => [$this->operation(), $this->operation(), $this->operation()],
        ], $this->bearer($token))->assertStatus(422)->assertJsonValidationErrors('operations');
    }

    public function test_push_updates_device_heartbeat_and_app_version(): void
    {
        $tenant = Tenant::factory()->create();
        [$device, $token] = $this->device($tenant, ['last_seen_at' => null, 'app_version' => '1.0.0']);

        $this->postJson('/api/v1/sync/push', ['operations' => [$this->operation()]], array_merge(
            $this->bearer($token),
            ['X-App-Version' => '1.2.3'],
        ))->assertOk();

        $device->refresh();
        $this->assertNotNull($device->last_seen_at);
        $this->assertSame('1.2.3', $device->app_version);
    }

    public function test_push_isolates_tenants_between_devices(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        [$deviceA, $tokenA] = $this->device($a);
        [, $tokenB] = $this->device($b);

        $this->postJson('/api/v1/sync/push', ['operations' => [$this->operation()]], $this->bearer($tokenA))->assertOk();
        $this->postJson('/api/v1/sync/push', ['operations' => [$this->operation()]], $this->bearer($tokenB))->assertOk();

        $this->assertSame(1, SyncInbox::query()->where('tenant_id', $a->id)->count());
        $this->assertSame(1, SyncInbox::query()->where('tenant_id', $b->id)->count());
        $this->assertSame(1, SyncInbox::query()->where('device_id', $deviceA->id)->count());
    }

    // ---------------------------------------------------------------- pull

    public function test_pull_requires_device_token(): void
    {
        $this->getJson('/api/v1/sync/pull')->assertStatus(401);
    }

    public function test_pull_returns_cursor_envelope(): void
    {
        $tenant = Tenant::factory()->create();
        [$device, $token] = $this->device($tenant);

        $this->getJson('/api/v1/sync/pull', $this->bearer($token))
            ->assertOk()
            ->assertJson([
                'device_id' => $device->id,
                'tenant_id' => $tenant->id,
                'has_more' => false,
                'changes' => [],
            ])
            ->assertJsonStructure(['device_id', 'tenant_id', 'server_time', 'cursor', 'has_more', 'changes']);
    }

    public function test_pull_accepts_cursor_and_limit(): void
    {
        $tenant = Tenant::factory()->create();
        [, $token] = $this->device($tenant);

        $this->getJson('/api/v1/sync/pull?cursor=abc&limit=10', $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('cursor', 'abc');

        $this->getJson('/api/v1/sync/pull?limit=0', $this->bearer($token))
            ->assertStatus(422)->assertJsonValidationErrors('limit');

        $this->getJson('/api/v1/sync/pull?limit=9999', $this->bearer($token))
            ->assertStatus(422)->assertJsonValidationErrors('limit');
    }

    public function test_pull_uses_device_tenant_not_query_parameter(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        [, $tokenA] = $this->device($a);

        $this->getJson('/api/v1/sync/pull?tenant_id='.$b->id, $this->bearer($tokenA))
            ->assertOk()
            ->assertJsonPath('tenant_id', $a->id);
    }
}
