<?php

namespace Tests\Feature\Users;

use App\Enums\Permission;
use App\Enums\Role;
use App\Exceptions\Auth\ActionForbidden;
use App\Models\User;
use App\Support\Auth\ActionAuthorizer;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ActionAuthorizerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private User $owner;

    private User $plainUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DefaultRolesSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::Owner->value);

        $this->plainUser = User::factory()->create();
    }

    public function test_user_with_permission_passes(): void
    {
        ActionAuthorizer::assert($this->owner, Permission::ManageUsers);

        $this->assertTrue(true);
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $this->expectException(ActionForbidden::class);
        $this->expectExceptionMessageMatches('/Mengelola pengguna/');

        ActionAuthorizer::assert($this->plainUser, Permission::ManageUsers);
    }

    public function test_forbidden_message_contains_permission_identifier(): void
    {
        try {
            ActionAuthorizer::assert($this->plainUser, Permission::RestoreBackup);
            $this->fail('Seharusnya dilempar ActionForbidden.');
        } catch (ActionForbidden $e) {
            $this->assertStringContainsString('backup.restore', $e->getMessage());
        }
    }

    public function test_inactive_user_is_always_forbidden_even_with_role(): void
    {
        $this->owner->update(['is_active' => false]);

        $this->expectException(ActionForbidden::class);
        $this->expectExceptionMessage('nonaktif');

        ActionAuthorizer::assert($this->owner, Permission::ManageUsers);
    }

    public function test_assert_if_without_actor_is_noop(): void
    {
        ActionAuthorizer::assertIf(null, Permission::RestoreBackup);

        $this->assertTrue(true);
    }

    public function test_assert_if_with_actor_enforces_permission(): void
    {
        $this->expectException(ActionForbidden::class);

        ActionAuthorizer::assertIf($this->plainUser, Permission::RestoreBackup);
    }

    public function test_unknown_permission_string_is_ignored(): void
    {
        // Katalog berubah masa depan: izin yang belum dikenal tidak memblokir
        // secara diam-diam (perilaku default aman tetap via Gate bila ada).
        ActionAuthorizer::assert($this->plainUser, 'future.permission');

        $this->assertTrue(true);
    }
}
