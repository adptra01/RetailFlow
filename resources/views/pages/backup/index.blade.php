<?php

use App\Backup\BackupInfo;
use App\Backup\BackupManager;
use App\Enums\Permission;
use App\Exceptions\Backup\BackupException;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;

name('backup.index');

middleware('auth');
middleware('verified');
// Modul backup/restore bersifat sensitif (FR-11 + FR-12): hanya pemegang izin
// `backup.restore` (Owner) yang boleh membuat maupun memulihkan backup.
middleware('can:'.Permission::RestoreBackup->value);

state([
    'encrypt' => false,
    'showRestoreModal' => false,
    'restoreTarget' => '',
    'confirmText' => '',
]);

/** BackupManager dari container (bisa diganti pada pengujian). */
$manager = fn (): BackupManager => app(BackupManager::class);

/** Daftar backup (terbaru lebih dulu) dari manifest. */
$backups = computed(function (): array {
    return $this->manager()->all();
});

$latest = computed(function (): ?BackupInfo {
    return $this->backups[0] ?? null;
});

$maxAgeDays = fn (): int => (int) config('pos.backup.max_age_days', 7);

/** True bila belum pernah backup atau backup terakhir lebih lama dari ambang (FR-10/FR-11). */
$overdue = computed(function (): bool {
    $latest = $this->latest;

    if ($latest === null || $latest->createdAt === null) {
        return true;
    }

    return Carbon::parse($latest->createdAt)->lt(now()->subDays($this->maxAgeDays()));
});

$humanSize = function (int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $index = 0;

    while ($bytes >= 1024 && $index < count($units) - 1) {
        $bytes /= 1024;
        $index++;
    }

    return $index === 0
        ? $bytes.' '.$units[$index]
        : number_format($bytes, 1, ',', '.').' '.$units[$index];
};

$createBackup = function () {
    try {
        $info = $this->manager()->create(null, $this->encrypt);
    } catch (BackupException $e) {
        Flux::toast(variant: 'danger', text: $e->getMessage());

        return;
    }

    Flux::toast(variant: 'success', text: __('Backup dibuat: :name', ['name' => $info->filename]));
};

$openRestore = function (string $filename) {
    $this->restoreTarget = $filename;
    $this->confirmText = '';
    $this->showRestoreModal = true;
};

/**
 * Pulihkan database dari backup terpilih (FR-11).
 *
 * Wajib konfirmasi eksplisit: mengetik `RESTORE`. `BackupManager` juga membuat
 * snapshot keselamatan otomatis sebelum menimpa, dan mencatat jejak audit
 * (FR-12) dengan aktor pengguna saat ini.
 */
$restoreBackup = function () {
    if (strtoupper(trim($this->confirmText)) !== 'RESTORE') {
        Flux::toast(variant: 'danger', text: __('Ketik RESTORE untuk mengonfirmasi pemulihan.'));

        return;
    }

    try {
        $safety = $this->manager()->restore($this->restoreTarget, true, auth()->user());
    } catch (BackupException $e) {
        Flux::toast(variant: 'danger', text: $e->getMessage());

        return;
    } catch (\Throwable $e) {
        Log::error('Restore backup gagal: '.$e->getMessage());

        Flux::toast(variant: 'danger', text: __('Restore gagal: :message', ['message' => $e->getMessage()]));

        return;
    }

    $this->showRestoreModal = false;
    $this->confirmText = '';

    Flux::toast(variant: 'success', text: __('Database dipulihkan. Snapshot pengaman: :name', [
        'name' => $safety->filename,
    ]));
};

?>

<x-layouts::app :title="__('Backup & Restore')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="{{ route('dashboard') }}">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Backup') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div>
                <flux:heading size="xl">{{ __('Backup & Restore') }}</flux:heading>
                <flux:subheading>
                    {{ __('Snapshot database yang konsisten untuk pemulihan bencana (FR-11). Ekspor data bukan pengganti backup.') }}
                </flux:subheading>
            </div>

            @if ($this->overdue)
                <flux:callout variant="warning" icon="exclamation-triangle"
                    heading="{{ __('Backup perlu dibuat') }}">
                    {{ $this->latest === null
                        ? __('Belum ada backup sama sekali. Segera buat backup pertama Anda.')
                        : __('Backup terakhir lebih lama dari :days hari.', ['days' => $this->maxAgeDays()]) }}
                </flux:callout>
            @endif

            {{-- Status --}}
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Backup Terakhir') }}</p>
                    <p class="mt-1 text-lg font-semibold">
                        {{ $this->latest?->createdAt ? Carbon::parse($this->latest->createdAt)->diffForHumans() : __('Belum ada') }}
                    </p>
                    @if ($this->latest?->createdAt)
                        <p class="text-xs text-zinc-500">{{ Carbon::parse($this->latest->createdAt)->format('d/m/Y H:i') }}</p>
                    @endif
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Jumlah Backup') }}</p>
                    <p class="mt-1 text-lg font-semibold">{{ count($this->backups) }}</p>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Versi Skema') }}</p>
                    <p class="mt-1 text-lg font-semibold font-mono">{{ config('pos.schema_version') }}</p>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Driver') }}</p>
                    <p class="mt-1 text-lg font-semibold">{{ $this->latest?->driver ?? '—' }}</p>
                </div>
            </div>

            {{-- Buat backup --}}
            <div class="flex flex-wrap items-end justify-between gap-4 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="space-y-2">
                    <flux:heading size="lg">{{ __('Buat Backup Sekarang') }}</flux:heading>
                    <flux:text class="text-sm text-zinc-500">
                        {{ __('Backup disimpan di: :path', ['path' => config('pos.backup.path')]) }}
                    </flux:text>
                    <flux:field variant="inline">
                        <flux:label>{{ __('Enkripsi backup (AES-256-CBC)') }}</flux:label>
                        <flux:switch wire:model.live="encrypt" />
                    </flux:field>
                </div>
                <flux:button variant="primary" icon="circle-stack" wire:click="createBackup"
                    wire:loading.attr="disabled" wire:target="createBackup">
                    {{ __('Buat Backup Sekarang') }}
                </flux:button>
            </div>

            {{-- Daftar backup --}}
            <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('Berkas') }}</flux:table.column>
                        <flux:table.column>{{ __('Waktu') }}</flux:table.column>
                        <flux:table.column>{{ __('Ukuran') }}</flux:table.column>
                        <flux:table.column>{{ __('Skema') }}</flux:table.column>
                        <flux:table.column>{{ __('Enkripsi') }}</flux:table.column>
                        <flux:table.column class="text-end">{{ __('Aksi') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($this->backups as $backup)
                            <flux:table.row :key="$backup->filename">
                                <flux:table.cell class="font-mono text-xs">{{ $backup->filename }}</flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap text-zinc-500">
                                    {{ $backup->createdAt ? Carbon::parse($backup->createdAt)->format('d/m/Y H:i') : '—' }}
                                </flux:table.cell>
                                <flux:table.cell>{{ $this->humanSize($backup->size) }}</flux:table.cell>
                                <flux:table.cell class="font-mono text-xs">{{ $backup->schemaVersion }}</flux:table.cell>
                                <flux:table.cell>
                                    @if ($backup->encrypted)
                                        <flux:badge color="emerald" size="sm" inset="top bottom">{{ __('Terenkripsi') }}</flux:badge>
                                    @else
                                        <flux:badge color="zinc" size="sm" inset="top bottom">{{ __('Tidak') }}</flux:badge>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex justify-end">
                                        <flux:button size="sm" variant="filled" icon="arrow-uturn-left"
                                            wire:click="openRestore('{{ $backup->filename }}')">
                                            {{ __('Pulihkan') }}
                                        </flux:button>
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="6" class="py-8 text-center text-zinc-500">
                                    {{ __('Belum ada backup.') }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>

            {{-- Modal restore --}}
            <flux:modal wire:model.self="showRestoreModal" class="max-w-lg">
                <form wire:submit="restoreBackup" class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Pulihkan Database') }}</flux:heading>
                        <flux:subheading>
                            {{ __('Database aktif akan DITIMPA dengan isi backup. Snapshot pengaman dibuat otomatis sebelum pemulihan.') }}
                        </flux:subheading>
                    </div>

                    <flux:callout variant="danger" icon="exclamation-triangle">
                        {{ __('Berkas: :name', ['name' => $restoreTarget]) }}
                    </flux:callout>

                    <flux:input wire:model.live="confirmText" :label="__('Ketik RESTORE untuk mengonfirmasi')"
                        placeholder="RESTORE" autocomplete="off" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="filled">{{ __('Batal') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="danger" type="submit"
                            :disabled="strtoupper(trim($confirmText)) !== 'RESTORE'">
                            {{ __('Pulihkan Sekarang') }}
                        </flux:button>
                    </div>
                </form>
            </flux:modal>
        </div>
    @endvolt
</x-layouts::app>
