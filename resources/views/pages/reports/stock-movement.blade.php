<?php

use App\Actions\Reports\GenerateStockMovementReport;
use App\Models\Product;
use Illuminate\Support\Number;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;

name('reports.stock-movement');

middleware('auth');
middleware('verified');

state([
    'from_date' => now()->startOfMonth()->format('Y-m-d'),
    'to_date' => now()->format('Y-m-d'),
    'product_id' => '',
])->url();

$products = computed(fn () => Product::query()->orderBy('name')->pluck('name', 'id'));

$options = fn () => [
    'from_date' => $this->from_date ?: null,
    'to_date' => $this->to_date ?: null,
    'product_id' => $this->product_id ?: null,
    'tenant_id' => auth()->user()->tenant_id,
];

$movements = computed(fn () => app(GenerateStockMovementReport::class)->handle($this->options()));

$summaryByType = computed(fn () => app(GenerateStockMovementReport::class)->getSummaryByType($this->options()));

$totals = computed(function () {
    $in = $this->movements->where('type', 'in')->sum('quantity_change');
    $out = $this->movements->where('type', 'out')->sum(fn ($m) => abs($m['quantity_change']));

    return ['in' => $in, 'out' => $out];
});

$typeLabels = fn ($type) => match ($type) {
    'App\Models\Transaction' => __('Penjualan'),
    'App\Models\TransactionItem' => __('Item Penjualan'),
    'App\Models\PurchaseOrder' => __('Pembelian (PO)'),
    'App\Models\PurchaseOrderItem' => __('Item PO'),
    'App\Models\GoodsReceipt' => __('Penerimaan Barang'),
    'App\Models\GoodsReceiptItem' => __('Item Penerimaan'),
    'App\Models\PurchaseReturn' => __('Retur Pembelian'),
    'App\Models\PurchaseReturnItem' => __('Item Retur'),
    'App\Models\StockOpname' => __('Stock Opname'),
    'App\Models\Menu' => __('Menu'),
    'App\Models\Order' => __('Pesanan'),
    'App\Models\OrderItem' => __('Item Pesanan'),
    default => ucwords(str_replace('_', ' ', class_basename($type))),
};

?>

<x-layouts::app :title="__('Stock Movement Report')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="/reports">{{ __('Reports') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Stock Movement') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="xl">{{ __('Stock Movement Report') }}</flux:heading>
                    <flux:subheading>{{ __('Mutasi stok masuk/keluar setiap produk dalam periode tertentu') }}</flux:subheading>
                </div>
                <flux:button variant="ghost" icon="arrow-left" href="/reports">
                    {{ __('Back to Reports') }}
                </flux:button>
            </div>

            <flux:card class="!p-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <flux:field>
                        <flux:label>{{ __('From') }}</flux:label>
                        <flux:input type="date" wire:model.live="from_date" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('To') }}</flux:label>
                        <flux:input type="date" wire:model.live="to_date" />
                    </flux:field>
                    <flux:field class="md:col-span-2">
                        <flux:label>{{ __('Product') }}</flux:label>
                        <flux:select wire:model.live="product_id" :label="__('Product')">
                            <flux:select.option value="">{{ __('Semua produk') }}</flux:select.option>
                            @foreach ($this->products as $id => $name)
                                <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                </div>
            </flux:card>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <flux:card class="!p-5">
                    <div class="text-sm text-zinc-500">{{ __('Total Masuk') }}</div>
                    <div class="mt-1 text-2xl font-semibold text-emerald-600">{{ Number::format($this->totals['in']) }}</div>
                    <div class="text-xs text-zinc-400">{{ __('unit stok masuk') }}</div>
                </flux:card>
                <flux:card class="!p-5">
                    <div class="text-sm text-zinc-500">{{ __('Total Keluar') }}</div>
                    <div class="mt-1 text-2xl font-semibold text-red-600">{{ Number::format($this->totals['out']) }}</div>
                    <div class="text-xs text-zinc-400">{{ __('unit stok keluar') }}</div>
                </flux:card>
                <flux:card class="!p-5">
                    <div class="text-sm text-zinc-500">{{ __('Jumlah Mutasi') }}</div>
                    <div class="mt-1 text-2xl font-semibold">{{ $this->movements->count() }}</div>
                    <div class="text-xs text-zinc-400">{{ __('catatan pergerakan stok') }}</div>
                </flux:card>
            </div>

            <flux:card class="!p-5">
                <flux:heading size="lg" class="mb-4">{{ __('Ringkasan per Sumber Mutasi') }}</flux:heading>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                    @forelse ($this->summaryByType as $type => $summary)
                        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                            <div class="text-sm font-medium">{{ $this->typeLabels($type) }}</div>
                            <div class="mt-2 flex items-center gap-3 text-sm">
                                <span class="text-emerald-600">+{{ Number::format($summary['total_in']) }}</span>
                                <span class="text-red-600">-{{ Number::format($summary['total_out']) }}</span>
                                <flux:badge size="sm" inset="top bottom">{{ $summary['count'] }}x</flux:badge>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-zinc-500">{{ __('Belum ada mutasi stok pada periode ini.') }}</p>
                    @endforelse
                </div>
            </flux:card>

            <flux:card class="!p-5">
                <flux:heading size="lg" class="mb-4">{{ __('Detail Mutasi Stok') }}</flux:heading>
                <div class="overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Tanggal') }}</flux:table.column>
                            <flux:table.column>{{ __('Produk') }}</flux:table.column>
                            <flux:table.column>{{ __('Sumber') }}</flux:table.column>
                            <flux:table.column>{{ __('Perubahan') }}</flux:table.column>
                            <flux:table.column>{{ __('Stok Awal') }}</flux:table.column>
                            <flux:table.column>{{ __('Stok Akhir') }}</flux:table.column>
                            <flux:table.column>{{ __('User') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->movements as $movement)
                                <flux:table.row :key="'sm-' . $movement['id']">
                                    <flux:table.cell class="text-xs whitespace-nowrap text-zinc-500">{{ $movement['date'] }}</flux:table.cell>
                                    <flux:table.cell>
                                        <div class="font-medium">{{ $movement['product_name'] }}</div>
                                        <div class="text-xs text-zinc-500">{{ $movement['product_sku'] }}</div>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <div class="text-xs">{{ $this->typeLabels($movement['reference_type']) }}</div>
                                        <div class="text-xs text-zinc-500">#{{ $movement['reference_id'] }}</div>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        @if ($movement['quantity_change'] > 0)
                                            <flux:badge color="green" size="sm" inset="top bottom">+{{ $movement['quantity_change'] }} {{ $movement['unit'] }}</flux:badge>
                                        @else
                                            <flux:badge color="red" size="sm" inset="top bottom">{{ $movement['quantity_change'] }} {{ $movement['unit'] }}</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell class="text-xs">{{ $movement['before_stock'] }}</flux:table.cell>
                                    <flux:table.cell class="text-xs font-medium">{{ $movement['after_stock'] }}</flux:table.cell>
                                    <flux:table.cell class="text-xs text-zinc-500">{{ $movement['user_name'] }}</flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="7" class="text-center text-zinc-500 py-6">
                                        {{ __('Tidak ada mutasi stok pada periode ini.') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>
            </flux:card>
        </div>
    @endvolt
</x-layouts::app>