<?php

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\User;
use Livewire\WithPagination;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

name('audit.index');

middleware('auth');
middleware('verified');
// Jejak audit adalah modul administratif FR-12 ("Pengguna dan audit"):
// hanya pemegang izin `user.manage` (Owner/Admin) yang boleh melihatnya.
middleware('can:'.Permission::ManageUsers->value);

uses(WithPagination::class);

state([
    'action' => '',
    'actor' => '',
    'from' => '',
    'to' => '',
])->url();

/** Daftar jejak audit tenant pengguna, terurut terbaru lebih dulu. */
$entries = computed(function () {
    $tenantId = auth()->user()?->tenant_id;

    // Fail-closed: pengguna tanpa tenant tidak melihat jejak audit apa pun.
    // (`whereNull('id')` menghasilkan paginator kosong yang valid.)
    if ($tenantId === null) {
        return AuditLog::query()->whereNull('id')->paginate(25);
    }

    return AuditLog::query()
        ->with(['actor', 'device'])
        ->where('tenant_id', $tenantId)
        ->when($this->action !== '', fn ($query) => $query->where('action', $this->action))
        ->when($this->actor !== '', fn ($query) => $query->where('actor_id', (int) $this->actor))
        ->when($this->from !== '', fn ($query) => $query->where('created_at', '>=', $this->from.' 00:00:00'))
        ->when($this->to !== '', fn ($query) => $query->where('created_at', '<=', $this->to.' 23:59:59'))
        ->orderByDesc('created_at')
        ->paginate(25);
});

/** Aktor yang punya jejak audit di tenant ini (untuk filter). */
$actors = computed(function () {
    $tenantId = auth()->user()?->tenant_id;

    if ($tenantId === null) {
        return collect();
    }

    $ids = AuditLog::query()
        ->where('tenant_id', $tenantId)
        ->whereNotNull('actor_id')
        ->distinct()
        ->pluck('actor_id');

    return User::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);
});

/** Jenis tindakan yang muncul pada data (untuk filter). */
$actions = computed(function () {
    $tenantId = auth()->user()?->tenant_id;

    if ($tenantId === null) {
        return collect();
    }

    return AuditLog::query()
        ->where('tenant_id', $tenantId)
        ->distinct()
        ->orderBy('action')
        ->pluck('action');
});

$actionLabel = function (string $action): string {
    $case = AuditAction::tryFrom($action);

    return match ($case) {
        AuditAction::SaleCreated => __('Penjualan dibuat'),
        AuditAction::ReceivablePayment => __('Pembayaran piutang'),
        AuditAction::UserCreated => __('Pengguna dibuat'),
        AuditAction::UserUpdated => __('Pengguna diperbarui'),
        AuditAction::UserDeactivated => __('Pengguna dinonaktifkan'),
        AuditAction::UserReactivated => __('Pengguna diaktifkan kembali'),
        AuditAction::UserRoleChanged => __('Peran pengguna diubah'),
        AuditAction::BackupRestored => __('Backup dipulihkan'),
        AuditAction::FinancialReportViewed => __('Laporan keuangan dilihat'),
        AuditAction::FeatureProfileChanged => __('Profil fitur diubah'),
        AuditAction::ModuleEnabled => __('Modul diaktifkan'),
        AuditAction::ModuleDisabled => __('Modul dinonaktifkan'),
        AuditAction::TenantConfigured => __('Konfigurasi toko diubah'),
        AuditAction::ReceiptPrintFailed => __('Cetak struk gagal'),
        AuditAction::DeviceRegistered => __('Perangkat didaftarkan'),
        AuditAction::DeviceRevoked => __('Perangkat dicabut'),
        AuditAction::PaymentChargeCreated => __('Pembayaran ke penyedia dibuat'),
        AuditAction::PaymentWebhookApplied => __('Status pembayaran diperbarui penyedia'),
        AuditAction::PaymentWebhookRejected => __('Webhook pembayaran ditolak'),
        AuditAction::PaymentWebhookUnmatched => __('Webhook pembayaran tanpa referensi'),
        AuditAction::PaymentReconciled => __('Status pembayaran diselaraskan (rekonsiliasi)'),
        default => $action,
    };
};

/** Metadata sebagai daftar `kunci: nilai` yang aman ditampilkan. */
$metadataPairs = function (?array $metadata): array {
    if (empty($metadata)) {
        return [];
    }

    return collect($metadata)
        ->map(fn ($value, $key) => $key.': '.(is_scalar($value) ? (string) $value : json_encode($value)))
        ->values()
        ->all();
};

?>

<x-layouts::app :title="__('Jejak Audit')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="{{ route('dashboard') }}">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Jejak Audit') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div>
                <flux:heading size="xl">{{ __('Jejak Audit') }}</flux:heading>
                <flux:subheading>
                    {{ __('Catatan tindakan sensitif: aktor, waktu, perangkat, jenis tindakan, dan alasan (FR-12).') }}
                </flux:subheading>
            </div>

            {{-- Filter --}}
            <div class="flex flex-wrap items-end gap-3">
                <flux:select wire:model.live="action" :label="__('Jenis tindakan')" class="max-w-56">
                    <flux:select.option value="">{{ __('Semua tindakan') }}</flux:select.option>
                    @foreach ($this->actions as $actionValue)
                        <flux:select.option value="{{ $actionValue }}">{{ $this->actionLabel($actionValue) }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="actor" :label="__('Aktor')" class="max-w-52">
                    <flux:select.option value="">{{ __('Semua aktor') }}</flux:select.option>
                    @foreach ($this->actors as $actorUser)
                        <flux:select.option value="{{ $actorUser->id }}">{{ $actorUser->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model.live="from" type="date" :label="__('Dari tanggal')" class="max-w-44" />
                <flux:input wire:model.live="to" type="date" :label="__('Sampai tanggal')" class="max-w-44" />
            </div>

            {{-- Daftar --}}
            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                <flux:table :paginate="$this->entries">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Waktu') }}</flux:table.column>
                        <flux:table.column>{{ __('Aktor') }}</flux:table.column>
                        <flux:table.column>{{ __('Tindakan') }}</flux:table.column>
                        <flux:table.column>{{ __('Entitas') }}</flux:table.column>
                        <flux:table.column>{{ __('Perangkat') }}</flux:table.column>
                        <flux:table.column>{{ __('Alasan / Detail') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($this->entries as $entry)
                            <flux:table.row :key="$entry->id">
                                <flux:table.cell class="whitespace-nowrap text-zinc-500">
                                    {{ $entry->created_at?->format('d/m/Y H:i') }}
                                </flux:table.cell>
                                <flux:table.cell class="font-medium">
                                    {{ $entry->actor?->name ?? __('Sistem') }}
                                </flux:table.cell>
                                <flux:table.cell>{{ $this->actionLabel($entry->action) }}</flux:table.cell>
                                <flux:table.cell class="text-zinc-500">
                                    @if ($entry->entity_type)
                                        <span class="font-mono text-xs">
                                            {{ $entry->entity_type }}{{ $entry->entity_id ? '#'.Str::limit((string) $entry->entity_id, 12, '…') : '' }}
                                        </span>
                                    @else
                                        —
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="text-zinc-500">
                                    {{ $entry->device?->device_name ?? '—' }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if ($entry->reason)
                                        <div class="text-sm">{{ $entry->reason }}</div>
                                    @endif
                                    @foreach ($this->metadataPairs($entry->metadata) as $pair)
                                        <div class="font-mono text-xs text-zinc-500">{{ $pair }}</div>
                                    @endforeach
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="6" class="py-8 text-center text-zinc-500">
                                    {{ __('Belum ada jejak audit untuk filter ini.') }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>
    @endvolt
</x-layouts::app>
