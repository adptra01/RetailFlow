<?php

use App\Actions\Products\ImportProducts;
use App\Support\Imports\ProductCsvParser;
use Flux\Flux;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\state;
use function Livewire\Volt\usesFileUploads;

name('imports.products');

middleware('auth');
middleware('verified');

usesFileUploads();

state([
    'file' => null,
    'step' => 'upload',
    // Baris hasil parse disimpan apa adanya untuk dikomit pada langkah berikutnya.
    'rows' => [],
    // Laporan pratinjau: total/valid/failed + rincian per baris.
    'report' => null,
    // Kesalahan tingkat berkas (header/kolom) dari parser.
    'fileErrors' => [],
    // Ringkasan setelah komit.
    'summary' => null,
]);

/**
 * Langkah 1 — pratinjau: validasi seluruh baris TANPA menyimpan (FR-02).
 */
$preview = function () {
    $this->validate(
        ['file' => ['required', 'file', 'max:2048', 'extensions:csv,txt']],
        [],
        ['file' => 'berkas'],
    );

    $parsed = (new ProductCsvParser)->parse((string) $this->file->get());

    $this->fileErrors = $parsed['errors'];
    $this->summary = null;

    if ($parsed['rows'] === []) {
        $this->rows = [];
        $this->report = null;
        $this->step = 'upload';

        Flux::toast(variant: 'danger', text: __('Berkas tidak dapat diproses. Periksa pesan kesalahan.'));

        return;
    }

    $result = (new ImportProducts(auth()->user()?->tenant_id))->preview($parsed['rows']);

    $this->rows = $parsed['rows'];
    $this->report = [
        'total' => $result->total,
        'valid' => $result->valid,
        'failed' => $result->failed,
        'rows' => $result->rows,
    ];
    $this->step = 'preview';
};

/**
 * Langkah 2 — komit: simpan baris valid dalam satu transaksi atomik (FR-02).
 */
$commit = function () {
    if ($this->rows === []) {
        return;
    }

    $result = (new ImportProducts(auth()->user()?->tenant_id))->commit($this->rows);

    $this->summary = [
        'created' => $result->created,
        'failed' => $result->failed,
        'total' => $result->total,
        'rows' => $result->rows,
    ];
    $this->report = null;
    $this->step = 'done';

    Flux::toast(
        variant: $result->created > 0 ? 'success' : 'warning',
        text: __('Impor selesai: :created produk dibuat, :failed baris gagal.', [
            'created' => $result->created,
            'failed' => $result->failed,
        ]),
    );
};

/**
 * Mulai ulang dari langkah unggah.
 */
$resetImport = function () {
    $this->file = null;
    $this->rows = [];
    $this->report = null;
    $this->fileErrors = [];
    $this->summary = null;
    $this->step = 'upload';
};

?>

<x-layouts::app :title="__('Impor Produk')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="{{ route('dashboard') }}">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item href="{{ route('products.index') }}">{{ __('Produk') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Impor') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            {{-- Header --}}
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <flux:heading size="xl">{{ __('Impor Produk') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Unggah berkas CSV, tinjau pratinjau, lalu simpan baris yang valid.') }}
                    </flux:subheading>
                </div>
                <flux:button variant="filled" icon="arrow-down-tray" href="{{ route('imports.products.template') }}">
                    {{ __('Unduh Template') }}
                </flux:button>
            </div>

            {{-- Kesalahan tingkat berkas --}}
            @if ($fileErrors !== [])
                <flux:callout variant="warning" icon="exclamation-triangle" heading="{{ __('Catatan pada berkas') }}">
                    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                        @foreach ($fileErrors as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </flux:callout>
            @endif

            {{-- Langkah 1 — unggah --}}
            @if ($step === 'upload')
                <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="grid gap-4">
                        <flux:input
                            type="file"
                            wire:model="file"
                            accept=".csv,.txt"
                            :label="__('Berkas CSV')"
                            :description="__('Maksimal 2 MB. Kolom wajib: name dan price.')"
                        />

                        <div class="rounded-lg bg-zinc-50 p-4 text-sm text-zinc-600 dark:bg-white/5 dark:text-zinc-300">
                            <p class="font-medium text-zinc-700 dark:text-zinc-200">{{ __('Kolom yang dikenali') }}</p>
                            <p class="mt-1 font-mono text-xs">
                                name, sku, barcode, category, price, buy_price, stock, min_stock, is_active
                            </p>
                            <p class="mt-2">
                                {{ __('Alias Bahasa Indonesia juga diterima (mis. nama, harga_jual, kategori, stok).') }}
                            </p>
                        </div>

                        <flux:error name="file" />

                        <div class="flex justify-end">
                            <flux:button
                                variant="primary"
                                icon="eye"
                                wire:click="preview"
                                wire:loading.attr="disabled"
                                wire:target="preview,file"
                            >
                                {{ __('Pratinjau') }}
                            </flux:button>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Langkah 2 — pratinjau --}}
            @if ($step === 'preview' && $report !== null)
                <div class="grid grid-cols-3 gap-4">
                    <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                        <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Total Baris') }}</p>
                        <p class="mt-1 text-2xl font-semibold">{{ $report['total'] }}</p>
                    </div>
                    <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                        <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Valid') }}</p>
                        <p class="mt-1 text-2xl font-semibold text-emerald-600 dark:text-emerald-400">{{ $report['valid'] }}</p>
                    </div>
                    <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                        <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Gagal') }}</p>
                        <p class="mt-1 text-2xl font-semibold text-rose-600 dark:text-rose-400">{{ $report['failed'] }}</p>
                    </div>
                </div>

                <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Baris') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                            <flux:table.column>{{ __('Nama') }}</flux:table.column>
                            <flux:table.column>{{ __('SKU') }}</flux:table.column>
                            <flux:table.column>{{ __('Kategori') }}</flux:table.column>
                            <flux:table.column>{{ __('Harga') }}</flux:table.column>
                            <flux:table.column>{{ __('Keterangan') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($report['rows'] as $row)
                                <flux:table.row :key="$row['line']">
                                    <flux:table.cell class="text-zinc-500">{{ $row['line'] }}</flux:table.cell>
                                    <flux:table.cell>
                                        @if ($row['status'] === 'valid')
                                            <flux:badge color="emerald" size="sm" inset="top bottom">{{ __('Valid') }}</flux:badge>
                                        @else
                                            <flux:badge color="rose" size="sm" inset="top bottom">{{ __('Gagal') }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="font-medium">{{ $row['data']['name'] ?? '-' }}</flux:table.cell>
                                    <flux:table.cell>{{ $row['data']['sku'] ?? '-' }}</flux:table.cell>
                                    <flux:table.cell>{{ $row['data']['category'] ?? '-' }}</flux:table.cell>
                                    <flux:table.cell>{{ $row['data']['price'] ?? '-' }}</flux:table.cell>
                                    <flux:table.cell>
                                        @if ($row['errors'] !== [])
                                            <ul class="list-disc space-y-0.5 pl-4 text-xs text-rose-600 dark:text-rose-400">
                                                @foreach ($row['errors'] as $error)
                                                    <li>{{ $error }}</li>
                                                @endforeach
                                            </ul>
                                        @else
                                            <span class="text-xs text-zinc-400">—</span>
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-3">
                    <flux:button variant="ghost" wire:click="resetImport">{{ __('Batal') }}</flux:button>
                    @can('products.create')
                        <flux:button
                            variant="primary"
                            icon="check"
                            wire:click="commit"
                            :disabled="$report['valid'] === 0"
                            wire:loading.attr="disabled"
                            wire:target="commit"
                        >
                            {{ __('Impor :count produk valid', ['count' => $report['valid']]) }}
                        </flux:button>
                    @endcan
                </div>
            @endif

            {{-- Langkah 3 — selesai --}}
            @if ($step === 'done' && $summary !== null)
                <flux:callout variant="success" icon="check-circle" heading="{{ __('Impor selesai') }}">
                    {{ __(':created produk dibuat, :failed baris gagal.', [
                        'created' => $summary['created'],
                        'failed' => $summary['failed'],
                    ]) }}
                </flux:callout>

                @if ($summary['failed'] > 0)
                    <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>{{ __('Baris') }}</flux:table.column>
                                <flux:table.column>{{ __('Status') }}</flux:table.column>
                                <flux:table.column>{{ __('Nama') }}</flux:table.column>
                                <flux:table.column>{{ __('Keterangan') }}</flux:table.column>
                            </flux:table.columns>

                            <flux:table.rows>
                                @foreach ($summary['rows'] as $row)
                                    @if ($row['status'] === 'failed' || $row['errors'] !== [])
                                        <flux:table.row :key="$row['line']">
                                            <flux:table.cell class="text-zinc-500">{{ $row['line'] }}</flux:table.cell>
                                            <flux:table.cell>
                                                <flux:badge color="rose" size="sm" inset="top bottom">{{ __('Gagal') }}</flux:badge>
                                            </flux:table.cell>
                                            <flux:table.cell class="font-medium">{{ $row['data']['name'] ?? '-' }}</flux:table.cell>
                                            <flux:table.cell>
                                                <ul class="list-disc space-y-0.5 pl-4 text-xs text-rose-600 dark:text-rose-400">
                                                    @foreach ($row['errors'] as $error)
                                                        <li>{{ $error }}</li>
                                                    @endforeach
                                                </ul>
                                            </flux:table.cell>
                                        </flux:table.row>
                                    @endif
                                @endforeach
                            </flux:table.rows>
                        </flux:table>
                    </div>
                @endif

                <div class="flex flex-wrap items-center justify-end gap-3">
                    <flux:button variant="ghost" wire:click="resetImport">{{ __('Impor Berkas Lain') }}</flux:button>
                    <flux:button variant="primary" icon="shopping-bag" href="{{ route('products.index') }}">
                        {{ __('Lihat Produk') }}
                    </flux:button>
                </div>
            @endif
        </div>
    @endvolt
</x-layouts::app>
