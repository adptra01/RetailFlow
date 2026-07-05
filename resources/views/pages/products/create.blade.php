<?php

use App\Models\{Category, Product};
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
    'name' => '',
    'slug' => '',
    'sku' => '',
    'price' => null,
    'stock' => null,
    'description' => '',
    'is_active' => true,
    'is_unlimited_stock' => false,
    'image' => null,
]);

$categoryOptions = computed(function () {
    return Category::orderBy('name')->get();
});

$previewInfo = computed(function () {
    if (! $this->image) {
        return null;
    }

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

$save = function () {
    if (empty($this->slug)) {
        $this->slug = str()->slug($this->name);
    }

    if (empty($this->sku)) {
        $this->sku = 'PRD-'.strtoupper(Str::random(8));
    }

    $validated = $this->validate([
        'category_id' => 'required|exists:categories,id',
        'name' => 'required|string|max:200',
        'slug' => 'required|string|max:220|unique:products,slug',
        'sku' => 'required|string|max:50|unique:products,sku',
        'price' => 'required|numeric|min:0',
        'stock' => 'required|integer|min:0',
        'description' => 'nullable|string',
        'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:2048',
        'is_active' => 'boolean',
        'is_unlimited_stock' => 'boolean',
    ]);

    if ($this->image) {
        $validated['image'] = $this->image->store('products', 'public');
    } else {
        unset($validated['image']);
    }

    Product::create($validated);

    $this->reset(['category_id', 'name', 'slug', 'sku', 'price', 'stock', 'image', 'description', 'is_unlimited_stock']);

    Flux::toast(variant: 'success', text: __('Product created.'));

    $this->dispatch('product-created');

    $this->redirectRoute('products.index');
};

$clearErrors = function () {
    $this->resetErrorBag();
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
                <flux:subheading>{{ __('Tambahkan produk baru.') }}</flux:subheading>
            </div>

            <form wire:submit="save">
                <div class="space-y-8">
                    {{-- Basic Information --}}
                    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="mb-6">
                            <flux:heading size="lg">{{ __('Basic Information') }}</flux:heading>
                            <flux:subheading>{{ __('Nama produk, kategori dan detail.') }}</flux:subheading>
                        </div>

                        <div class="space-y-5">
                            <flux:select wire:model="category_id" :label="__('Category')" placeholder="{{ __('Pilih kategori...') }}">
                                @foreach ($this->categoryOptions as $category)
                                    <flux:select.option value="{{ $category->id }}">
                                        {{ $category->name }}
                                    </flux:select.option>
                                @endforeach
                            </flux:select>

                            <flux:input wire:model="name" :label="__('Product Name')" placeholder="{{ __('Masukkan nama produk') }}" required autofocus />

                            {{-- <flux:input wire:model="slug" :label="__('Slug')" placeholder="{{ __('otomatis dari nama') }}" /> --}}

                            <div class="grid grid-cols-2 gap-5">
                                <flux:input wire:model="sku" :label="__('SKU')" placeholder="{{ __('otomatis jika kosong') }}" />
                                <flux:input wire:model="stock" :label="__('Stock')" type="number" min="0" placeholder="0" :disabled="$is_unlimited_stock" />

                            </div>
                            <flux:field variant="inline">
                                <flux:label>{{ __('Tanpa Stok') }}</flux:label>
                                <flux:switch wire:model.live="is_unlimited_stock" />
                            </flux:field>
                        </div>
                    </div>

                    {{-- Image --}}
                    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="mb-6">
                            <flux:heading size="lg">{{ __('Gambar Produk') }}</flux:heading>
                            <flux:subheading>{{ __('Upload gambar produk. Format: JPEG, PNG, WebP. Maks: 2MB.') }}</flux:subheading>
                        </div>

                        <input
                            type="file"
                            wire:model="image"
                            accept="image/jpeg,image/png,image/jpg,image/webp"
                            class="block w-full text-sm text-zinc-500 file:me-3 file:rounded-lg file:border-0 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-white file:shadow-xs file:bg-zinc-800 hover:file:bg-zinc-700 dark:file:bg-white/10 dark:hover:file:bg-white/20 dark:file:text-zinc-200"
                        >

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

                    {{-- Pricing --}}
                    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="mb-6">
                            <flux:heading size="lg">{{ __('Pricing') }}</flux:heading>
                            <flux:subheading>{{ __('Tentukan harga produk.') }}</flux:subheading>
                        </div>

                        <flux:input wire:model="price" :label="__('Price')" type="number" step="0.01" min="0" placeholder="0.00" prefix="Rp" />
                    </div>

                    {{-- Details --}}
                    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="mb-6">
                            <flux:heading size="lg">{{ __('Details') }}</flux:heading>
                            <flux:subheading>{{ __('Deskripsi produk dan status.') }}</flux:subheading>
                        </div>

                        <div class="space-y-5">
                            <flux:textarea wire:model="description" :label="__('Description')" placeholder="{{ __('Masukkan deskripsi produk...') }}" />

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
