<?php

use App\Models\Product;
use Flux\Flux;
use Livewire\WithPagination;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

name('products.index');

middleware('auth');
middleware('verified');

uses(WithPagination::class);

state([
    'search' => '',
    'sortBy' => 'id',
    'sortDirection' => 'asc',
])->url();

state([
    'showDetailModal' => false,
    'detailProduct' => null,
    'showDeleteModal' => false,
    'deletingProductId' => null,
]);

$sort = function ($column) {
    if ($this->sortBy === $column) {
        $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
    } else {
        $this->sortBy = $column;
        $this->sortDirection = 'asc';
    }
};

$products = computed(function () {
    return Product::query()
        ->with(['category', 'brand', 'units'])
        ->where(function ($q) {
            $q->where('name', 'like', '%' . $this->search . '%')
              ->orWhere('sku', 'like', '%' . $this->search . '%')
              ->orWhere('barcode', 'like', '%' . $this->search . '%');
        })
        ->orderBy($this->sortBy, $this->sortDirection)
        ->paginate(10);
});

$totalProducts = computed(function () {
    return Product::count();
});

$activeProducts = computed(function () {
    return Product::where('is_active', true)->count();
});

$unlimitedStockProducts = computed(function () {
    return Product::where('is_unlimited_stock', true)->count();
});

$lowStockProducts = computed(function () {
    return Product::where('is_unlimited_stock', false)->where('stock', '>', 0)
        ->where(function ($q) { $q->where('stock', '<', \DB::raw('min_stock'))->orWhere('stock', '<', 5); })
        ->count();
});

$outOfStockProducts = computed(function () {
    return Product::where('is_unlimited_stock', false)->where('stock', '<=', 0)->count();
});

$confirmDelete = function ($id) {
    $this->deletingProductId = $id;
    $this->showDeleteModal = true;
};

$viewProduct = function ($id) {
    $this->detailProduct = Product::with(['category', 'brand', 'supplier', 'units'])->findOrFail($id);
    $this->showDetailModal = true;
};

$delete = function () {
    $product = Product::findOrFail($this->deletingProductId);
    $product->delete();

    $this->deletingProductId = null;
    $this->showDeleteModal = false;

    Flux::toast(variant: 'success', text: __('Product deleted.'));
};

?>

<x-layouts::app :title="__('Products')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Products') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            {{-- Header --}}
            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="xl">{{ __('Products') }}</flux:heading>
                    <flux:subheading>{{ __('Manage your product inventory') }}</flux:subheading>
                </div>
                <flux:button variant="primary" icon="plus" href="{{ route('products.create') }}">
                    {{ __('Add Product') }}
                </flux:button>
            </div>

            {{-- Stats --}}
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Total Products') }}</p>
                    <p class="mt-1 text-2xl font-semibold">{{ $this->totalProducts }}</p>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Active') }}</p>
                    <p class="mt-1 text-2xl font-semibold text-emerald-600 dark:text-emerald-400">
                        {{ $this->activeProducts }}</p>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Low Stock') }}</p>
                    <p class="mt-1 text-2xl font-semibold text-amber-600 dark:text-amber-400">{{ $this->lowStockProducts }}
                    </p>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Tanpa Stok') }}</p>
                    <p class="mt-1 text-2xl font-semibold text-purple-600 dark:text-purple-400">{{ $this->unlimitedStockProducts }}
                    </p>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Out of Stock') }}</p>
                    <p class="mt-1 text-2xl font-semibold text-rose-600 dark:text-rose-400">{{ $this->outOfStockProducts }}
                    </p>
                </div>
            </div>

            {{-- Search --}}
            <flux:input size="md" wire:model.live="search" type="search"
                placeholder="{{ __('Search by name or SKU...') }}" />
            <div
                class="relative h-full flex-1 overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700 p-6">

                {{-- Table --}}
                <flux:table :paginate="$this->products">
                    <flux:table.columns>
                        <flux:table.column>
                            {{ __('Image') }}
                        </flux:table.column>

                        <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection"
                            wire:click="sort('name')">
                            {{ __('Name') }}
                        </flux:table.column>

                        <flux:table.column>
                            {{ __('Category') }}
                        </flux:table.column>

                        <flux:table.column>
                            {{ __('Brand') }}
                        </flux:table.column>

                        <flux:table.column sortable :sorted="$sortBy === 'sku'" :direction="$sortDirection"
                            wire:click="sort('sku')">
                            {{ __('SKU') }}
                        </flux:table.column>

                        <flux:table.column sortable :sorted="$sortBy === 'price'" :direction="$sortDirection"
                            wire:click="sort('price')">
                            {{ __('Harga Jual') }}
                        </flux:table.column>

                        <flux:table.column sortable :sorted="$sortBy === 'stock'" :direction="$sortDirection"
                            wire:click="sort('stock')">
                            {{ __('Stock') }}
                        </flux:table.column>

                        <flux:table.column sortable :sorted="$sortBy === 'is_active'" :direction="$sortDirection"
                            wire:click="sort('is_active')">
                            {{ __('Status') }}
                        </flux:table.column>

                        <flux:table.column>
                            {{ __('Actions') }}
                        </flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->products as $product)
                            <flux:table.row :key="$product->id">
                                <flux:table.cell>
                                    <img src="{{ $product->image_url }}" class="h-10 w-10 rounded-lg border border-zinc-200 object-cover dark:border-zinc-700">
                                </flux:table.cell>

                                <flux:table.cell class="font-medium">{{ $product->name }}</flux:table.cell>

                                <flux:table.cell>
                                    <flux:badge size="sm" inset="top bottom">
                                        {{ $product->category?->name ?? '-' }}
                                    </flux:badge>
                                </flux:table.cell>

                                <flux:table.cell>
                                    <span class="text-xs text-zinc-500">{{ $product->brand?->name ?? '-' }}</span>
                                </flux:table.cell>

                                <flux:table.cell variant="strong">
                                    <code class="text-xs">{{ $product->sku }}</code>
                                </flux:table.cell>

                                <flux:table.cell>
                                    {{ Number::currency($product->price, 'IDR', 'id') }}
                                </flux:table.cell>

                                <flux:table.cell>
                                    @if($product->is_unlimited_stock)
                                        <flux:badge color="purple" size="sm" inset="top bottom">
                                            {{ __('Tanpa Stok') }}
                                        </flux:badge>
                                    @else
                                        @php
                                            $stock = $product->stock;
                                            $min = max(1, $product->min_stock ?: 5);
                                            $color = $stock <= 0 ? 'red' : ($stock < $min ? 'amber' : 'green');
                                            $bgClass = $stock <= 0 ? 'bg-rose-500' : ($stock < $min ? 'bg-amber-500' : 'bg-emerald-500');
                                            $percentage = min(100, ($stock / ($min * 2)) * 100);
                                        @endphp
                                        <div class="flex w-44 flex-col gap-1.5">
                                            <div class="flex items-center justify-between">
                                                <flux:badge :color="$color" size="sm" inset="top bottom">
                                                    {{ $product->total_stock_label }}
                                                </flux:badge>
                                                <span class="text-[10px] font-medium text-zinc-500">
                                                    {{ $stock <= 0 ? __('Critical') : ($stock < $min ? __('Low Stock') : __('Healthy')) }}
                                                </span>
                                            </div>
                                            <div class="h-1.5 w-full rounded-full bg-zinc-100 dark:bg-zinc-700">
                                                <div class="h-1.5 rounded-full {{ $bgClass }}" style="width: {{ $percentage }}%"></div>
                                            </div>
                                        </div>
                                    @endif
                                </flux:table.cell>

                                <flux:table.cell>
                                    <flux:badge :color="$product->is_active ? 'green' : 'red'" size="sm"
                                        inset="top bottom">
                                        {{ $product->is_active ? __('Active') : __('Inactive') }}
                                    </flux:badge>
                                </flux:table.cell>

                                <flux:table.cell>
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal"
                                            inset="top bottom" />
                                        <flux:menu>
                                            <flux:menu.item icon="eye" wire:click="viewProduct({{ $product->id }})">
                                                {{ __('View') }}
                                            </flux:menu.item>
                                            <flux:menu.item icon="pencil"
                                                href="{{ route('products.edit', ['product' => $product->id]) }}">
                                                {{ __('Edit') }}
                                            </flux:menu.item>
                                            <flux:menu.separator />
                                            <flux:menu.item icon="trash" variant="danger"
                                                wire:click="confirmDelete({{ $product->id }})">
                                                {{ __('Delete') }}
                                            </flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>

            </div>

            {{-- Detail Modal --}}
            <flux:modal wire:model.self="showDetailModal" class="max-w-4xl w-full">
                @if ($detailProduct)
                    <div class="space-y-8">
                        {{-- Header --}}
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0 flex-1">
                                <flux:heading size="xl" class="truncate">{{ $detailProduct->name }}</flux:heading>
                                <flux:subheading>{{ __('Product details') }}</flux:subheading>
                            </div>
                            <flux:badge :color="$detailProduct->is_active ? 'emerald' : 'red'" size="lg">
                                {{ $detailProduct->is_active ? __('Active') : __('Inactive') }}
                            </flux:badge>
                        </div>

                        {{-- Info Grid --}}
                        <div class="grid grid-cols-2 gap-x-10 gap-y-8">
                            <div>
                                <p class="text-xs font-medium uppercase tracking-wider text-zinc-400">{{ __('Category') }}
                                </p>
                                <p class="mt-1.5 text-base font-medium">{{ $detailProduct->category?->name ?? '-' }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-medium uppercase tracking-wider text-zinc-400">{{ __('SKU') }}
                                </p>
                                <p class="mt-1.5 text-base font-medium">
                                    <code
                                        class="rounded-md bg-zinc-100 px-2 py-0.5 text-sm dark:bg-zinc-700">{{ $detailProduct->sku }}</code>
                                </p>
                            </div>
                            <div>
                                <p class="text-xs font-medium uppercase tracking-wider text-zinc-400">{{ __('Price') }}
                                </p>
                                <p class="mt-1.5 text-2xl font-semibold">
                                    {{ Number::currency($detailProduct->price, 'IDR', 'id') }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-medium uppercase tracking-wider text-zinc-400">{{ __('Stock') }}
                                </p>
                                @if($detailProduct->is_unlimited_stock)
                                    <p class="mt-1.5 text-2xl font-semibold text-purple-600">{{ __('Tanpa Stok') }}</p>
                                @else
                                    <p class="mt-1.5 text-2xl font-semibold">
                                        <span :class="$detailProduct->stock < 5 ? 'text-red-600' : 'text-green-600'">{{ $detailProduct->stock }}</span>
                                        <span class="text-sm font-normal text-zinc-400">{{ __('units') }}</span>
                                    </p>
                                @endif
                            </div>
                        </div>

                        {{-- Description --}}
                        @if ($detailProduct->description)
                            <div>
                                <p class="text-xs font-medium uppercase tracking-wider text-zinc-400">
                                    {{ __('Description') }}</p>
                                <p class="mt-2 text-base leading-relaxed text-zinc-600 dark:text-zinc-300">
                                    {{ $detailProduct->description }}</p>
                            </div>
                        @endif

                        {{-- Footer --}}
                        <div class="flex items-center justify-between border-t border-zinc-200 pt-4 dark:border-zinc-700">
                            <div class="flex gap-6 text-xs text-zinc-400">
                                <span>{{ __('Created') }} {{ $detailProduct->created_at->format('d M Y, H:i') }}</span>
                                <span>{{ __('Updated') }} {{ $detailProduct->updated_at->format('d M Y, H:i') }}</span>
                            </div>
                            <flux:modal.close>
                                <flux:button variant="filled">{{ __('Close') }}</flux:button>
                            </flux:modal.close>
                        </div>
                    </div>
                @endif
            </flux:modal>

            {{-- Delete Confirmation Modal --}}
            <flux:modal wire:model.self="showDeleteModal" class="max-w-lg">
                <form wire:submit="delete" class="space-y-6">
                    <div class="flex items-start gap-4">
                        <div
                            class="flex size-10 shrink-0 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30">
                            <flux:icon name="exclamation-triangle" variant="micro" class="text-red-600" />
                        </div>
                        <div>
                            <flux:heading size="lg">{{ __('Delete Product') }}</flux:heading>
                            <flux:subheading class="mt-1">
                                {{ __('Are you sure you want to delete this product? Transaction records will be preserved. This action cannot be undone.') }}
                            </flux:subheading>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                        <flux:modal.close>
                            <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="danger" type="submit">{{ __('Delete') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        </div>
    @endvolt
</x-layouts::app>
