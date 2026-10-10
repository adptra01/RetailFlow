<?php

use App\Actions\Sync\ResolveSyncConflict;
use App\Actions\Sync\SyncNow;
use App\Models\Device;
use App\Models\SyncConflict;
use App\Reports\SyncStatusReport;
use Flux\Flux;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

name('sync.index');

middleware('auth');
middleware('verified');

state([
    'summary' => [],
    'conflicts' => [],
    'unsynced' => [],
]);

/**
 * Perangkat yang ditampilkan: perangkat aktif paling baru dari tenant pengguna
 * (mode MVP satu perangkat per instalasi, §12).
 */
$device = computed(function () {
    $tenantId = auth()->user()?->tenant_id;

    return Device::query()
        ->when($tenantId, fn ($query) => $query->where('tenant_id', $tenantId))
        ->whereNull('revoked_at')
        ->orderByDesc('last_seen_at')
        ->first();
});

$refresh = function () {
    $report = new SyncStatusReport(auth()->user()?->tenant_id, $this->device?->id);

    $this->summary = $report->summary();
    $this->conflicts = $report->openConflicts();
    $this->unsynced = $report->unsyncedOperations();
};

mount(function () {
    $this->refresh();
});

$syncNow = function () {
    try {
        $summary = (new SyncNow(auth()->user()?->tenant_id, $this->device?->id))->handle();

        Flux::toast(variant: 'success', text: __(
            'Sinkronisasi selesai: :synced tersinkron, :failed gagal, :conflict konflik, :retrying menunggu diulang.',
            [
                'synced' => $summary->synced,
                'failed' => $summary->failed,
                'conflict' => $summary->conflicted,
                'retrying' => $summary->retrying,
            ]
        ));
    } catch (\Throwable $e) {
        Flux::toast(variant: 'danger', text: $e->getMessage());
    }

    $this->refresh();
};

$resolveConflict = function (int $id) {
    $conflict = SyncConflict::query()->findOrFail($id);

    try {
        (new ResolveSyncConflict(auth()->user()?->tenant_id, auth()->user()))->allow($conflict);
        Flux::toast(variant: 'success', text: __('Konflik diselesaikan: penjualan diterapkan dengan kebijakan stok negatif.'));
    } catch (\Throwable $e) {
        Flux::toast(variant: 'danger', text: $e->getMessage());
    }

    $this->refresh();
};

$dismissConflict = function (int $id) {
    $conflict = SyncConflict::query()->findOrFail($id);

    try {
        (new ResolveSyncConflict(auth()->user()?->tenant_id, auth()->user()))->dismiss($conflict);
        Flux::toast(variant: 'success', text: __('Konflik ditutup tanpa penerapan.'));
    } catch (\Throwable $e) {
        Flux::toast(variant: 'danger', text: $e->getMessage());
    }

    $this->refresh();
};

$stateBadge = function (string $state): array {
    return match ($state) {
        'attention' => ['label' => 'Perlu perhatian', 'color' => 'danger'],
        'pending' => ['label' => 'Menunggu sinkron', 'color' => 'amber'],
        'synced' => ['label' => 'Tersinkron', 'color' => 'green'],
        default => ['label' => 'Belum ada aktivitas', 'color' => 'zinc'],
    };
};

?>

<x-layouts::app :title="__('Sinkronisasi')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Sinkronisasi') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <flux:heading size="lg">{{ __('Status Sinkronisasi') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Pantau antrean operasi, perangkat, dan konflik yang perlu diselesaikan.') }}
                    </flux:subheading>
                </div>

                <flux:button variant="primary" icon="arrow-path" wire:click="syncNow" wire:loading.attr="disabled">
                    {{ __('Sinkronkan sekarang') }}
                </flux:button>
            </div>

            @php($labels = $this->stateBadge($summary['state'] ?? 'unknown'))

            <div class="flex flex-wrap items-center gap-3">
                <flux:badge :color="$labels['color']" size="lg">{{ __($labels['label']) }}</flux:badge>
                @if ($this->device)
                    <flux:text>
                        {{ __('Perangkat') }}: {{ $this->device->device_name }}
                        @if ($this->device->last_seen_at)
                            · {{ __('terakhir terlihat') }} {{ $this->device->last_seen_at->diffForHumans() }}
                        @endif
                    </flux:text>
                @else
                    <flux:text>{{ __('Belum ada perangkat terdaftar untuk tenant ini.') }}</flux:text>
                @endif
            </div>

            {{-- Ringkasan antrean --}}
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-sm text-zinc-500">{{ __('Operasi tertunda') }}</p>
                    <p class="mt-1 text-2xl font-bold">{{ $summary['pending_user_visible'] ?? 0 }}</p>
                    <p class="mt-1 text-xs text-zinc-500">
                        {{ __('Menunggu') }}: {{ $summary['queued'] ?? 0 }}
                    </p>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-sm text-zinc-500">{{ __('Tersinkron') }}</p>
                    <p class="mt-1 text-2xl font-bold">{{ $summary['synced'] ?? 0 }}</p>
                    <p class="mt-1 text-xs text-zinc-500">
                        @if ($summary['last_synced_at'] ?? null)
                            {{ __('Terakhir') }}:
                            {{ \Illuminate\Support\Carbon::parse($summary['last_synced_at'])->diffForHumans() }}
                        @else
                            {{ __('Belum pernah tersinkron') }}
                        @endif
                    </p>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-sm text-zinc-500">{{ __('Gagal') }}</p>
                    <p class="mt-1 text-2xl font-bold">{{ $summary['failed'] ?? 0 }}</p>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-sm text-zinc-500">{{ __('Konflik terbuka') }}</p>
                    <p class="mt-1 text-2xl font-bold">{{ $summary['open_conflicts'] ?? 0 }}</p>
                </div>
            </div>

            {{-- Konflik --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="lg" class="mb-4">{{ __('Konflik yang perlu diselesaikan') }}</flux:heading>

                @if (empty($conflicts))
                    <flux:text>{{ __('Tidak ada konflik terbuka.') }}</flux:text>
                @else
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Jenis') }}</flux:table.column>
                            <flux:table.column>{{ __('Operasi') }}</flux:table.column>
                            <flux:table.column>{{ __('Detail') }}</flux:table.column>
                            <flux:table.column>{{ __('Waktu') }}</flux:table.column>
                            <flux:table.column />
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($conflicts as $conflict)
                                <flux:table.row :key="$conflict['id']">
                                    <flux:table.cell>{{ $conflict['type_label'] }}</flux:table.cell>
                                    <flux:table.cell>
                                        <span class="font-mono text-xs">{{ $conflict['operation_id'] }}</span>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        @if (($conflict['details']['reason'] ?? null) === 'insufficient_stock')
                                            {{ __('Stok tidak mencukupi untuk produk #:id (tersedia :available, diminta :requested).', [
                                                'id' => $conflict['details']['product_id'] ?? '-',
                                                'available' => $conflict['details']['available'] ?? '-',
                                                'requested' => $conflict['details']['requested'] ?? '-',
                                            ]) }}
                                        @else
                                            {{ __('Stok negatif setelah penerapan.') }}
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $conflict['created_at'] }}</flux:table.cell>
                                    <flux:table.cell>
                                        @can('stock.adjust')
                                            <div class="flex justify-end gap-2">
                                                <flux:button size="sm" variant="filled"
                                                    wire:click="resolveConflict({{ $conflict['id'] }})">
                                                    {{ __('Terapkan') }}
                                                </flux:button>
                                                <flux:button size="sm" variant="ghost"
                                                    wire:click="dismissConflict({{ $conflict['id'] }})">
                                                    {{ __('Tutup') }}
                                                </flux:button>
                                            </div>
                                        @endcan
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif
            </div>

            {{-- Operasi belum tersinkron --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="lg" class="mb-4">{{ __('Operasi belum tersinkron') }}</flux:heading>

                @if (empty($unsynced))
                    <flux:text>{{ __('Semua operasi sudah tersinkron.') }}</flux:text>
                @else
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Urutan') }}</flux:table.column>
                            <flux:table.column>{{ __('Entitas') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column>{{ __('Percobaan') }}</flux:table.column>
                            <flux:table.column>{{ __('Pesan') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($unsynced as $entry)
                                <flux:table.row :key="$entry['operation_id']">
                                    <flux:table.cell>{{ $loop->iteration }}</flux:table.cell>
                                    <flux:table.cell>
                                        {{ $entry['entity_type'] }} · {{ $entry['operation_type'] }}
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge size="sm" inset="top bottom">{{ $entry['sync_status_label'] }}</flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell>{{ $entry['retry_count'] }}</flux:table.cell>
                                    <flux:table.cell>
                                        <span class="text-xs text-zinc-500">{{ $entry['last_error'] ?? '—' }}</span>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif
            </div>
        </div>
    @endvolt
</x-layouts::app>
