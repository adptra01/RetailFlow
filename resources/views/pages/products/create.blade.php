<?php

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use Flux\Flux;
use Illuminate\Support\Str;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\{state, usesFileUploads};

name('products.create');
middleware('auth');
middleware('verified');
usesFileUploads();

state([
    'category_id' => null,
    'brand_id' => null,
    'supplier_id' => null,
    'name' => '',
    'slug' => '',
    'sku' => '',
    'barcode' => '',
    'buy_price' => 0,
    'price' => null,
    'stock' => null,
    'min_stock' => 0,
    'description' => '',
    'is_active' => true,
    'is_unlimited_stock' => false,
    'image' => null,
    'units' => [['name' => 'PCS', 'abbreviation' => 'PCS', 'conversion_factor' => 1, 'is_base' => true, 'price' => 0, 'purchase_price' => 0]],
]);

$categoryOptions = computed(fn () => Category::orderBy('name')->get());
$brandOptions = computed(fn () => Brand::orderBy('name')->get());
$supplierOptions = computed(fn () => Supplier::orderBy('name')->get());

$previewInfo = computed(function () {
    if (! $this->image) return null;
    return [
        'url' => $this->image->temporaryUrl(),
        'name' => $this->image->getClientOriginalName(),
        'size' => $this->image->getSize(),
    ];
});

$updatedName = function () {
    if (empty($this->slug) || $this->slug === str()->slug($this->name)) {
        $this->slug = str()->slug($this->name);
    }
};

$addUnit = function () {
    $hasBase = collect($this->units)->contains('is_base', true);
    $this->units[] = ['name' => '', 'abbreviation' => '', 'conversion_factor' => 1, 'is_base' => !$hasBase, 'price' => 0, 'purchase_price' => 0];
};

$removeUnit = function ($index) {
    if ($this->units[$index]['is_base']) return;
    array_splice($this->units, $index, 1);
};

$setBaseUnit = function ($index) {
    foreach ($this->units as $i => &$u) {
        $u['is_base'] = $i === $index;
    }
};

$save = function () {
    if (empty($this->slug)) $this->slug = str()->slug($this->name);
    if (empty($this->sku)) $this->sku = 'PRD-' . strtoupper(Str::random(8));

    $validated = $this->validate([
        'category_id' => 'required|exists:categories,id',
        'brand_id' => 'nullable|exists:brands,id',
        'supplier_id' => 'nullable|exists:suppliers,id',
        'name' => 'required|string|max:200',
        'slug' => 'required|string|max:220|unique:products,slug',
        'sku' => 'required|string|max:50|unique:products,sku',
        'barcode' => 'nullable|string|max:100|unique:products,barcode',
        'buy_price' => 'nullable|numeric|min:0',
        'price' => 'required|numeric|min:0',
        'stock' => 'required|integer|min:0',
        'min_stock' => 'nullable|integer|min:0',
        'description' => 'nullable|string',
        'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        'is_active' => 'boolean',
        'is_unlimited_stock' => 'boolean',
        'units.*.name' => 'required|string|max:50',
        'units.*.abbreviation' => 'required|string|max:20',
        'units.*.conversion_factor' => 'required|integer|min:1',
        'units.*.price' => 'nullable|numeric|min:0',
        'units.*.purchase_price' => 'nullable|numeric|min:0',
    ]);

    if ($this->image) {
        $validated['image'] = $this->image->store('products', 'public');
    } else {
        unset($validated['image']);
    }

    $product = Product::create($validated);

    foreach ($this->units as $unit) {
        $product->units()->create([
            'name' => $unit['name'],
            'abbreviation' => $unit['abbreviation'],
            'conversion_factor' => $unit['conversion_factor'],
            'is_base' => $unit['is_base'],
            'price' => $unit['price'] ?? 0,
            'purchase_price' => $unit['purchase_price'] ?? 0,
        ]);
    }

    $this->reset(['category_id', 'brand_id', 'supplier_id', 'name', 'slug', 'sku', 'barcode', 'buy_price', 'price', 'stock', 'min_stock', 'image', 'description', 'is_unlimited_stock']);
    $this->units = [['name' => 'PCS', 'abbreviation' => 'PCS', 'conversion_factor' => 1, 'is_base' => true, 'price' => 0, 'purchase_price' => 0]];

    Flux::toast(variant: 'success', text: __('Product created.'));
    $this->redirectRoute('products.index');
};

?>

<x-layouts::app :title="__('Create Product')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="dashboard">{{ __('Dashboard') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item href="{{ route('products.index') }}">{{ __('Products') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Create') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div>
                <flux:heading size="xl">{{ __('Create Product') }}</flux:heading>
                <flux:subheading>{{ __('Add a new product with units.') }}</flux:subheading>
            </div>

            <form wire:submit="save">
                <div class="space-y-8">
                    {{-- Basic Information --}}
                    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="mb-6">
                            <flux:heading size="lg">{{ __('Basic Information') }}</flux:heading>
                            <flux:subheading>{{ __('Product name, category, brand, and identifiers.') }}</flux:subheading>
                        </div>

                        <div class="space-y-5">
                            <div class="grid grid-cols-2 gap-4">
                                <flux:select wire:model="category_id" :label="__('Category')" placeholder="{{ __('Pilih kategori...') }}">
                                    @foreach ($this->categoryOptions as $category)
                                        <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:select wire:model="brand_id" :label="__('Brand')" placeholder="{{ __('Pilih brand...') }}">
                                    @foreach ($this->brandOptions as $brand)
                                        <flux:select.option value="{{ $brand->id }}">{{ $brand->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>

                            <flux:input wire:model="name" :label="__('Product Name')" placeholder="{{ __('Product name') }}" required autofocus />
                            <div class="grid grid-cols-2 gap-4">
                                <flux:input wire:model="sku" :label="__('SKU')" placeholder="{{ __('Auto if empty') }}" />
                                <flux:input wire:model="barcode" :label="__('Barcode')" placeholder="{{ __('Optional') }}" />
                            </div>

                            <flux:select wire:model="supplier_id" :label="__('Supplier')" placeholder="{{ __('Pilih supplier...') }}">
                                @foreach ($this->supplierOptions as $supplier)
                                    <flux:select.option value="{{ $supplier->id }}">{{ $supplier->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                    </div>

                    {{-- Pricing & Stock --}}
                    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="mb-6">
                            <flux:heading size="lg">{{ __('Pricing & Stock') }}</flux:heading>
                            <flux:subheading>{{ __('Purchase price, selling price, and stock.') }}</flux:subheading>
                        </div>

                        <div class="space-y-5">
                            <div class="grid grid-cols-2 gap-4">
                                <flux:input wire:model="buy_price" :label="__('Purchase Price')" type="number" step="0.01" min="0" prefix="Rp" />
                                <flux:input wire:model="price" :label="__('Selling Price')" type="number" step="0.01" min="0" prefix="Rp" required />
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <flux:input wire:model="stock" :label="__('Stock')" type="number" min="0" placeholder="0" :disabled="$is_unlimited_stock" />
                                <flux:input wire:model="min_stock" :label="__('Min Stock')" type="number" min="0" placeholder="0" />
                            </div>

                            <flux:field variant="inline">
                                <flux:label>{{ __('Tanpa Stok') }}</flux:label>
                                <flux:switch wire:model.live="is_unlimited_stock" />
                            </flux:field>
                        </div>
                    </div>

                    {{-- Multi Satuan --}}
                    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="mb-6 flex items-center justify-between">
                            <div>
                                <flux:heading size="lg">{{ __('Multi Satuan') }}</flux:heading>
                                <flux:subheading>{{ __('Define product units (e.g. PCS, Pack, Dus).') }}</flux:subheading>
                            </div>
                            <flux:button size="sm" variant="ghost" icon="plus" wire:click="addUnit">{{ __('Add Unit') }}</flux:button>
                        </div>

                        <div class="space-y-3">
                            @foreach ($this->units as $index => $unit)
                                <div class="flex items-end gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                    <div class="flex-1">
                                        <flux:input wire:model="units.{{ $index }}.name" :label="__('Name')" placeholder="{{ __('Dus') }}" class="text-xs" />
                                    </div>
                                    <div class="w-20">
                                        <flux:input wire:model="units.{{ $index }}.abbreviation" :label="__('Abbrev')" placeholder="{{ __('Dus') }}" class="text-xs" />
                                    </div>
                                    <div class="w-24">
                                        <flux:input wire:model="units.{{ $index }}.conversion_factor" :label="__('Conv Factor')" type="number" min="1" placeholder="1" class="text-xs" :disabled="$unit['is_base']" />
                                    </div>
                                    <div class="w-28">
                                        <flux:input wire:model="units.{{ $index }}.price" :label="__('Sell Price')" type="number" step="0.01" min="0" class="text-xs" />
                                    </div>
                                    <div class="w-28">
                                        <flux:input wire:model="units.{{ $index }}.purchase_price" :label="__('Buy Price')" type="number" step="0.01" min="0" class="text-xs" />
                                    </div>
                                    <div class="flex items-center gap-1 pb-1">
                                        @if (!$unit['is_base'])
                                            <button type="button" wire:click="setBaseUnit({{ $index }})"
                                                class="rounded p-1.5 text-xs text-zinc-400 hover:text-blue-600" title="{{ __('Set as base unit') }}">
                                                <flux:icon name="star" variant="micro" />
                                            </button>
                                            <button type="button" wire:click="removeUnit({{ $index }})"
                                                class="rounded p-1.5 text-xs text-zinc-400 hover:text-red-600">
                                                <flux:icon name="trash" variant="micro" />
                                            </button>
                                        @else
                                            <flux:badge size="sm" color="blue" inset="top bottom" class="text-[10px]">{{ __('Base') }}</flux:badge>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Image --}}
                    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="mb-6">
                            <flux:heading size="lg">{{ __('Product Image') }}</flux:heading>
                            <flux:subheading>{{ __('Upload product image. JPEG, PNG, WebP. Max 2MB.') }}</flux:subheading>
                        </div>

                        <input type="file" wire:model="image" accept="image/jpeg,image/png,image/jpg,image/webp"
                            class="block w-full text-sm text-zinc-500 file:me-3 file:rounded-lg file:border-0 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white file:shadow-xs file:bg-zinc-800 hover:file:bg-zinc-700 dark:file:bg-white/10 dark:hover:file:bg-white/20 dark:file:text-zinc-200">

                        @if ($this->previewInfo)
                            <div class="mt-4 flex items-center gap-4 rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                                <img src="{{ $this->previewInfo['url'] }}" class="h-16 w-16 rounded-lg border border-zinc-200 object-cover dark:border-zinc-700">
                                <div class="flex-1 min-w-0">
                                    <p class="truncate text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ $this->previewInfo['name'] }}</p>
                                    <p class="text-xs text-zinc-400">{{ number_format($this->previewInfo['size'] / 1024, 1) }} KB</p>
                                </div>
                                <button type="button" wire:click="$set('image', null)" class="shrink-0 rounded-lg p-1.5 text-zinc-400 hover:bg-zinc-200 hover:text-red-500 dark:hover:bg-zinc-700">
                                    <flux:icon name="x-mark" variant="mini" class="size-5" />
                                </button>
                            </div>
                        @endif
                        @error('image')
                            <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- Details --}}
                    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="mb-6">
                            <flux:heading size="lg">{{ __('Details') }}</flux:heading>
                            <flux:subheading>{{ __('Description and status.') }}</flux:subheading>
                        </div>

                        <div class="space-y-5">
                            <flux:textarea wire:model="description" :label="__('Description')" placeholder="{{ __('Product description...') }}" />
                            <flux:field variant="inline">
                                <flux:label>{{ __('Active') }}</flux:label>
                                <flux:switch wire:model.live="is_active" />
                            </flux:field>
                        </div>
                    </div>

                    {{-- Actions --}}
                    <div class="flex justify-end gap-2">
                        <flux:button variant="filled" href="{{ route('products.index') }}">{{ __('Cancel') }}</flux:button>
                        <flux:button variant="primary" type="submit">{{ __('Save Product') }}</flux:button>
                    </div>
                </div>
            </form>
        </div>
    @endvolt
</x-layouts::app>
