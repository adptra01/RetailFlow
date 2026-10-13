<?php

use App\Models\Alert;
use App\Support\Alerts\AlertGenerator;
use Flux\Flux;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;

name('alerts.index');

middleware('auth');
middleware('verified');

state([
    'scope' => 'unread', // unread | all
    'severity' => '',    // '' | info | warning | critical
    'type' => '',        // '' | stock.low | product.incomplete_data | receivable.due | backup.overdue | sync.backlog | sync.failed | device.stale | payment.awaiting_verification | stock.discrepancy
])->url();

/**
 * Daftar alert sesuai filter. Memakai `computed` agar otomatis dihitung ulang
 * setiap `scope`/`severity`/`type` berubah dan setiap kali aksi mengubah data.
 */
$alerts = computed(function () {
    $tenantId = auth()->user()?->tenant_id;

    // Fail-closed: pengguna tanpa tenant tidak melihat alert apa pun.
    if ($tenantId === null) {
        return collect();
    }

    return Alert::query()
        ->where('tenant_id', $tenantId)
        ->when($this->scope === 'unread', fn ($query) => $query->whereNull('read_at'))
        ->when($this->severity !== '', fn ($query) => $query->where('severity', $this->severity))
        ->when($this->type !== '', fn ($query) => $query->where('alert_type', $this->type))
        ->orderByDesc('created_at')
        ->limit(100)
        ->get();
});

$counts = computed(function () {
    $tenantId = auth()->user()?->tenant_id;

    if ($tenantId === null) {
        return ['unread' => 0, 'info' => 0, 'warning' => 0, 'critical' => 0, 'total' => 0];
    }

    $base = fn () => Alert::query()->where('tenant_id', $tenantId);

    return [
        'unread' => $base()->whereNull('read_at')->count(),
        'info' => $base()->where('severity', Alert::SEVERITY_INFO)->whereNull('read_at')->count(),
        'warning' => $base()->where('severity', Alert::SEVERITY_WARNING)->whereNull('read_at')->count(),
        'critical' => $base()->where('severity', Alert::SEVERITY_CRITICAL)->whereNull('read_at')->count(),
        'total' => $base()->count(),
    ];
});

/** Jenis alert yang dikenal (untuk filter). */
$alertTypes = function (): array {
    return [
        Alert::TYPE_STOCK_LOW,
        Alert::TYPE_PRODUCT_INCOMPLETE_DATA,
        Alert::TYPE_RECEIVABLE_DUE,
        Alert::TYPE_BACKUP_OVERDUE,
        Alert::TYPE_SYNC_BACKLOG,
        Alert::TYPE_SYNC_FAILED,
        Alert::TYPE_DEVICE_STALE,
        Alert::TYPE_PAYMENT_UNVERIFIED,
        Alert::TYPE_STOCK_DISCREPANCY,
    ];
};

$typeLabel = function (string $type): string {
    return match ($type) {
        Alert::TYPE_STOCK_LOW => __('Stok menipis'),
        Alert::TYPE_PRODUCT_INCOMPLETE_DATA => __('Data produk belum lengkap'),
        Alert::TYPE_RECEIVABLE_DUE => __('Piutang jatuh tempo'),
        Alert::TYPE_BACKUP_OVERDUE => __('Backup terlalu lama'),
        Alert::TYPE_SYNC_BACKLOG => __('Sinkronisasi tertunda'),
        Alert::TYPE_SYNC_FAILED => __('Sinkronisasi gagal'),
        Alert::TYPE_DEVICE_STALE => __('Perangkat belum tersinkron'),
        Alert::TYPE_PAYMENT_UNVERIFIED => __('Pembayaran menunggu verifikasi'),
        Alert::TYPE_STOCK_DISCREPANCY => __('Selisih stok'),
        default => $type,
    };
};

$severityLabel = function (string $severity): string {
    return match ($severity) {
        Alert::SEVERITY_INFO => __('Info'),
        Alert::SEVERITY_WARNING => __('Perhatian'),
        Alert::SEVERITY_CRITICAL => __('Kritis'),
        default => $severity,
    };
};

$severityColor = function (string $severity): string {
    return match ($severity) {
        Alert::SEVERITY_INFO => 'sky',
        Alert::SEVERITY_WARNING => 'amber',
        Alert::SEVERITY_CRITICAL => 'rose',
        default => 'zinc',
    };
};

/** Kalimat ringkas yang menjelaskan isi payload alert. */
$describe = function (Alert $alert): string {
    $payload = $alert->payload ?? [];

    return match ($alert->alert_type) {
        Alert::TYPE_STOCK_LOW => __('Stok :name tinggal :stock (minimum :min).', [
            'name' => $payload['product_name'] ?? '-',
            'stock' => $payload['stock'] ?? '-',
            'min' => $payload['min_stock'] ?? '-',
        ]),
        Alert::TYPE_PRODUCT_INCOMPLETE_DATA => __('Data produk :name belum lengkap (harga/barcode).', [
            'name' => $payload['product_name'] ?? '-',
        ]),
        Alert::TYPE_RECEIVABLE_DUE => __('Piutang jatuh tempo, sisa :amount (minor unit).', [
            'amount' => $payload['remaining_minor'] ?? 0,
        ]),
        Alert::TYPE_BACKUP_OVERDUE => __('Backup terakhir :at; segera buat backup baru.', [
            'at' => $payload['last_backup_at'] ?? '-',
        ]),
        Alert::TYPE_SYNC_BACKLOG => __('Perangkat :name punya :count operasi menunggu terkirim sejak :at.', [
            'name' => $payload['device_name'] ?? '-',
            'count' => $payload['pending_count'] ?? 0,
            'at' => $payload['oldest_at'] ?? '-',
        ]),
        Alert::TYPE_SYNC_FAILED => __('Perangkat :name memiliki :count operasi ditolak server.', [
            'name' => $payload['device_name'] ?? '-',
            'count' => $payload['failed_count'] ?? 0,
        ]),
        Alert::TYPE_DEVICE_STALE => __('Perangkat :name belum menyapa server sejak :at.', [
            'name' => $payload['device_name'] ?? '-',
            'at' => $payload['last_seen_at'] ?? '-',
        ]),
        Alert::TYPE_PAYMENT_UNVERIFIED => __('Pembayaran :invoice menunggu verifikasi (:method).', [
            'invoice' => $payload['invoice_number'] ?? '-',
            'method' => $payload['payment_method'] ?? '-',
        ]),
        Alert::TYPE_STOCK_DISCREPANCY => __('Selisih stok :name: sistem :system, fisik :actual (selisih :diff).', [
            'name' => $payload['product_name'] ?? '-',
            'system' => $payload['system_stock'] ?? '-',
            'actual' => $payload['actual_stock'] ?? '-',
            'diff' => $payload['difference'] ?? '-',
        ]),
        default => $alert->alert_type,
    };
};

$findAlert = function (int $id): ?Alert {
    $tenantId = auth()->user()?->tenant_id;

    if ($tenantId === null) {
        return null;
    }

    return Alert::query()
        ->where('tenant_id', $tenantId)
        ->find($id);
};

$markRead = function (int $id) {
    $this->findAlert($id)?->markAsRead();
};

$markAllRead = function () {
    $tenantId = auth()->user()?->tenant_id;

    if ($tenantId === null) {
        return;
    }

    $count = Alert::query()
        ->where('tenant_id', $tenantId)
        ->whereNull('read_at')
        ->update(['read_at' => now()]);

    Flux::toast(variant: 'success', text: __(':count alert ditandai sudah dibaca.', ['count' => $count]));
};

/** Jalankan pemeriksaan kondisi lokal sekarang (FR-10), lalu tampilkan hasilnya. */
$runChecks = function () {
    $tenantId = auth()->user()?->tenant_id;

    if ($tenantId === null) {
        Flux::toast(variant: 'danger', text: __('Toko belum terhubung ke organisasi.'));

        return;
    }

    $created = (new AlertGenerator($tenantId))->run();

    Flux::toast(
        variant: $created > 0 ? 'success' : 'info',
        text: $created > 0
            ? __('Pemeriksaan selesai: :count alert baru.', ['count' => $created])
            : __('Pemeriksaan selesai: tidak ada alert baru.'),
    );
};

?>

<x-layouts::app :title="__('Notifikasi')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="{{ route('dashboard') }}">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Notifikasi') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <flux:heading size="xl">{{ __('Notifikasi') }}</flux:heading>
                    <flux:subheading>{{ __('Peringatan otomatis dari kondisi toko (stok, produk, piutang, backup, sinkronisasi, pembayaran).') }}</flux:subheading>
                </div>
                <div class="flex items-center gap-2">
                    <flux:button variant="filled" icon="arrow-path" wire:click="runChecks" wire:loading.attr="disabled"
                        wire:target="runChecks">
                        {{ __('Periksa Sekarang') }}
                    </flux:button>
                    <flux:button variant="primary" icon="check" wire:click="markAllRead"
                        :disabled="$this->counts['unread'] === 0" wire:loading.attr="disabled" wire:target="markAllRead">
                        {{ __('Tandai Semua Dibaca') }}
                    </flux:button>
                </div>
            </div>

            {{-- Ringkasan --}}
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Belum Dibaca') }}</p>
                    <p class="mt-1 text-2xl font-semibold">{{ $this->counts['unread'] }}</p>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Info') }}</p>
                    <p class="mt-1 text-2xl font-semibold text-sky-600 dark:text-sky-400">{{ $this->counts['info'] }}</p>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Perhatian') }}</p>
                    <p class="mt-1 text-2xl font-semibold text-amber-600 dark:text-amber-400">{{ $this->counts['warning'] }}</p>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Kritis') }}</p>
                    <p class="mt-1 text-2xl font-semibold text-rose-600 dark:text-rose-400">{{ $this->counts['critical'] }}</p>
                </div>
            </div>

            {{-- Filter --}}
            <div class="flex flex-wrap items-center gap-3">
                <flux:select wire:model.live="scope" class="max-w-40">
                    <flux:select.option value="unread">{{ __('Belum dibaca') }}</flux:select.option>
                    <flux:select.option value="all">{{ __('Semua') }}</flux:select.option>
                </flux:select>

                <flux:select wire:model.live="severity" class="max-w-40">
                    <flux:select.option value="">{{ __('Semua tingkat') }}</flux:select.option>
                    <flux:select.option value="{{ Alert::SEVERITY_INFO }}">{{ __('Info') }}</flux:select.option>
                    <flux:select.option value="{{ Alert::SEVERITY_WARNING }}">{{ __('Perhatian') }}</flux:select.option>
                    <flux:select.option value="{{ Alert::SEVERITY_CRITICAL }}">{{ __('Kritis') }}</flux:select.option>
                </flux:select>

                <flux:select wire:model.live="type" class="max-w-56">
                    <flux:select.option value="">{{ __('Semua jenis') }}</flux:select.option>
                    @foreach ($this->alertTypes() as $alertType)
                        <flux:select.option value="{{ $alertType }}">{{ $this->typeLabel($alertType) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            {{-- Daftar --}}
            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Tingkat') }}</flux:table.column>
                        <flux:table.column>{{ __('Jenis') }}</flux:table.column>
                        <flux:table.column>{{ __('Keterangan') }}</flux:table.column>
                        <flux:table.column>{{ __('Waktu') }}</flux:table.column>
                        <flux:table.column class="text-end">{{ __('Aksi') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($this->alerts as $alert)
                            <flux:table.row :key="$alert->id">
                                <flux:table.cell>
                                    <flux:badge :color="$this->severityColor($alert->severity)" size="sm" inset="top bottom">
                                        {{ $this->severityLabel($alert->severity) }}
                                    </flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>{{ $this->typeLabel($alert->alert_type) }}</flux:table.cell>
                                <flux:table.cell class="font-medium">{{ $this->describe($alert) }}</flux:table.cell>
                                <flux:table.cell class="text-zinc-500">{{ $alert->created_at?->diffForHumans() }}</flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex items-center justify-end gap-2">
                                        @if (! empty($alert->payload['target']))
                                            <flux:button size="sm" variant="ghost" icon="arrow-top-right-on-square"
                                                href="{{ $alert->payload['target'] }}">
                                                {{ __('Buka') }}
                                            </flux:button>
                                        @endif

                                        @if ($alert->read_at === null)
                                            <flux:button size="sm" variant="filled" icon="check"
                                                wire:click="markRead({{ $alert->id }})">
                                                {{ __('Tandai') }}
                                            </flux:button>
                                        @else
                                            <flux:badge color="zinc" size="sm" inset="top bottom">{{ __('Dibaca') }}</flux:badge>
                                        @endif
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="5" class="py-8 text-center text-zinc-500">
                                    {{ __('Tidak ada notifikasi untuk filter ini.') }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>
    @endvolt
</x-layouts::app>
