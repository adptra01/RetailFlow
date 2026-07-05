<?php

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Flux\Flux;
use Livewire\WithPagination;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;

name('purchases.create');
middleware('auth');
middleware('verified');

state([
    'supplier_id' => null,
    'notes' => '',
    'search' => '',
    'cart' => [],
    'showUnitModal' => false,
    'selectedProduct' => null,
    'selectedUnitId' => null,
]);

$supplierOptions = computed(fn () => Supplier::orderBy('name')->get());

$products = computed(function () {
    if (empty($this->search)) return collect();
    return Product::query()->with('units')
        ->where('is_active', true)
        ->where(function ($q) {
            $q->where('name', 'like', '%' . $this->search . '%')
              ->orWhere('sku', 'like', '%' . $this->search . '%')
              ->orWhere('barcode', 'like', '%' . $this->search . '%');
        })
        ->orderBy('name')
        ->limit(10)
        ->get();
});

$totalAmount = computed(fn () => collect($this->cart)->sum('subtotal'));

$openUnitSelector = function ($productId) {
    $this->selectedProduct = Product::with('units')->findOrFail($productId);
    $this->selectedUnitId = $this->selectedProduct->units()->where('is_active', true)->first()?->id;
    $this->showUnitModal = true;
};

$addToCart = function () {
    $product = $this->selectedProduct;
    if (!$product || !$this->selectedUnitId) {
        Flux::toast(variant: 'error', text: __('Please select a unit.'));
        return;
    }
    $unit = ProductUnit::findOrFail($this->selectedUnitId);

    $cartKey = $product->id . '-' . $unit->id;
    foreach ($this->cart as &$item) {
        if ($item['cart_key'] === $cartKey) {
            $item['quantity']++;
            $item['subtotal'] = $item['quantity'] * $item['unit_price'];
            $this->showUnitModal = false;
            return;
        }
    }

    $this->cart[] = [
        'cart_key' => $cartKey,
        'product_id' => $product->id,
        'product_unit_id' => $unit->id,
        'unit_name' => $unit->name,
        'unit_abbreviation' => $unit->abbreviation,
        'conversion_factor' => $unit->conversion_factor,
        'name' => $product->name,
        'unit_price' => (float) ($unit->purchase_price ?: $product->buy_price),
        'quantity' => 1,
        'subtotal' => (float) ($unit->purchase_price ?: $product->buy_price),
    ];

    $this->showUnitModal = false;
    $this->selectedProduct = null;
};

$updateQty = function ($index, $qty) {
    if (isset($this->cart[$index])) {
        $this->cart[$index]['quantity'] = max(1, (int) $qty);
        $this->cart[$index]['subtotal'] = $this->cart[$index]['quantity'] * $this->cart[$index]['unit_price'];
    }
};

$updatePrice = function ($index, $price) {
    if (isset($this->cart[$index])) {
        $this->cart[$index]['unit_price'] = (float) max(0, $price);
        $this->cart[$index]['subtotal'] = $this->cart[$index]['quantity'] * $this->cart[$index]['unit_price'];
    }
};

$removeItem = function ($index) {
    array_splice($this->cart, $index, 1);
};

$saveDraft = function () { $this->save('draft'); };

$submitPO = function () { $this->save('pending'); };

$save = function ($status) {
    if (empty($this->supplier_id)) {
        Flux::toast(variant: 'error', text: __('Please select a supplier.'));
        return;
    }
    if (empty($this->cart)) {
        Flux::toast(variant: 'error', text: __('Please add at least one product.'));
        return;
    }

    $validated = $this->validate([
        'supplier_id' => 'required|exists:suppliers,id',
        'notes' => 'nullable|string|max:500',
    ]);

    $po = PurchaseOrder::create([
        'supplier_id' => $this->supplier_id,
        'user_id' => auth()->id(),
        'status' => $status,
        'total_amount' => $this->totalAmount,
        'notes' => $this->notes,
    ]);

    foreach ($this->cart as $item) {
        $po->items()->create([
            'product_id' => $item['product_id'],
            'product_unit_id' => $item['product_unit_id'],
            'quantity' => $item['quantity'],
            'unit_price' => $item['unit_price'],
            'subtotal' => $item['subtotal'],
            'received_quantity' => 0,
        ]);
    }

    Flux::toast(variant: 'success', text: __('Purchase order created.'));

    $this->redirect('/purchases/' . $po->id, navigate: true);
};

?>

<x-layouts::app :title="__('Create Purchase Order')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item href="/purchases">{{ __('Purchase Orders') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Create') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div>
                <flux:heading size="xl">{{ __('Create Purchase Order') }}</flux:heading>
                <flux:subheading>{{ __('Order products from supplier.') }}</flux:subheading>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div class="space-y-6 lg:col-span-2">
                    {{-- Supplier --}}
                    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                        <flux:select wire:model="supplier_id" :label="__('Supplier')" placeholder="{{ __('Pilih supplier...') }}" required>
                            @foreach ($this->supplierOptions as $supplier)
                                <flux:select.option value="{{ $supplier->id }}">{{ $supplier->name }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <div class="mt-5">
                            <flux:textarea wire:model="notes" :label="__('Notes')" placeholder="{{ __('Notes for this order...') }}" rows="2" />
                        </div>
                    </div>

                    {{-- Product Search --}}
                    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="mb-4">
                            <flux:heading size="lg">{{ __('Products') }}</flux:heading>
                            <flux:subheading>{{ __('Search and add products to this order.') }}</flux:subheading>
                        </div>

                        <flux:input wire:model.live="search" type="search" placeholder="{{ __('Search products by name, SKU, or barcode...') }}" />

                        @if ($this->search && $this->products->isNotEmpty())
                            <div class="mt-3 divide-y divide-zinc-100 dark:divide-zinc-700">
                                @foreach ($this->products as $product)
                                    <div class="flex items-center justify-between py-2">
                                        <div>
                                            <p class="text-sm font-medium">{{ $product->name }}</p>
                                            <p class="text-xs text-zinc-500">SKU: {{ $product->sku }}</p>
                                        </div>
                                        <flux:button size="xs" variant="primary" wire:click="openUnitSelector({{ $product->id }})">
                                            {{ __('Add') }}
                                        </flux:button>
                                    </div>
                                @endforeach
                            </div>
                        @elseif ($this->search)
                            <p class="mt-3 text-sm text-zinc-500">{{ __('No products found.') }}</p>
                        @endif
                    </div>

                    {{-- Cart Items --}}
                    @if (count($this->cart) > 0)
                        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                            <flux:heading size="lg">{{ __('Order Items') }}</flux:heading>
                            <div class="mt-4 overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b text-left text-xs font-medium text-zinc-500 dark:border-zinc-700">
                                            <th class="pb-2 pr-4">{{ __('Product') }}</th>
                                            <th class="pb-2 pr-4">{{ __('Unit') }}</th>
                                            <th class="pb-2 pr-4">{{ __('Qty') }}</th>
                                            <th class="pb-2 pr-4">{{ __('Price') }}</th>
                                            <th class="pb-2 pr-4 text-right">{{ __('Subtotal') }}</th>
                                            <th class="pb-2"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($this->cart as $index => $item)
                                            <tr class="border-b border-zinc-50 dark:border-zinc-800">
                                                <td class="py-2 pr-4 font-medium">{{ $item['name'] }}</td>
                                                <td class="py-2 pr-4 text-xs text-zinc-500">{{ $item['unit_abbreviation'] }}</td>
                                                <td class="py-2 pr-4">
                                                    <input type="number" min="1" value="{{ $item['quantity'] }}"
                                                        wire:change="updateQty({{ $index }}, $event.target.value)"
                                                        class="w-16 rounded border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-600 dark:bg-zinc-700">
                                                </td>
                                                <td class="py-2 pr-4">
                                                    <input type="number" step="0.01" min="0" value="{{ $item['unit_price'] }}"
                                                        wire:change="updatePrice({{ $index }}, $event.target.value)"
                                                        class="w-24 rounded border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-600 dark:bg-zinc-700">
                                                </td>
                                                <td class="py-2 pr-4 text-right font-medium">{{ Number::currency($item['subtotal'], 'IDR', 'id') }}</td>
                                                <td class="py-2">
                                                    <button type="button" wire:click="removeItem({{ $index }})"
                                                        class="text-xs text-red-500 hover:text-red-700">{{ __('Remove') }}</button>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Summary --}}
                <div class="lg:col-span-1">
                    <div class="sticky top-6 space-y-5">
                        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                            <flux:heading size="lg">{{ __('Summary') }}</flux:heading>
                            <div class="mt-4 space-y-3">
                                <div class="flex justify-between text-sm">
                                    <span class="text-zinc-500">{{ __('Items') }}</span>
                                    <span class="font-medium">{{ count($this->cart) }}</span>
                                </div>
                                <div class="flex justify-between border-t pt-3 text-base font-bold dark:border-zinc-700">
                                    <span>{{ __('Total') }}</span>
                                    <span class="text-blue-600">{{ Number::currency($this->totalAmount, 'IDR', 'id') }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="flex flex-col gap-2">
                            <flux:button variant="primary" wire:click="submitPO" class="w-full justify-center" :disabled="empty($this->cart) || !$this->supplier_id">
                                {{ __('Submit PO') }}
                            </flux:button>
                            <flux:button variant="filled" wire:click="saveDraft" class="w-full justify-center" :disabled="empty($this->cart) || !$this->supplier_id">
                                {{ __('Save as Draft') }}
                            </flux:button>
                            <flux:button variant="ghost" href="/purchases" class="w-full justify-center">
                                {{ __('Cancel') }}
                            </flux:button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Unit Selector Modal --}}
            <flux:modal wire:model.self="showUnitModal" class="max-w-md">
                @if ($selectedProduct)
                    <form wire:submit="addToCart" class="space-y-6">
                        <div>
                            <flux:heading size="lg">{{ __('Pilih Satuan') }}</flux:heading>
                            <flux:subheading>{{ $selectedProduct->name }}</flux:subheading>
                        </div>

                        <div class="space-y-2">
                            @php $activeUnits = $selectedProduct->units()->where('is_active', true)->get(); @endphp
                            @forelse ($activeUnits as $unit)
                                <label wire:key="unit-{{ $unit->id }}" class="flex cursor-pointer items-center gap-3 rounded-lg border border-zinc-200 p-3 transition hover:border-blue-400 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50 dark:border-zinc-700 dark:has-[:checked]:border-blue-500 dark:has-[:checked]:bg-blue-900/20">
                                    <input type="radio" name="unit" value="{{ $unit->id }}" wire:model="selectedUnitId" class="h-4 w-4 text-blue-600">
                                    <div class="flex-1">
                                        <p class="text-sm font-medium">{{ $unit->name }} ({{ $unit->abbreviation }})</p>
                                        <p class="text-xs text-zinc-500">{{ Number::currency($unit->purchase_price ?: $selectedProduct->buy_price, 'IDR', 'id') }}</p>
                                    </div>
                                    @if ($unit->is_base)
                                        <flux:badge size="xs" color="blue" inset="top bottom">{{ __('Base') }}</flux:badge>
                                    @endif
                                </label>
                            @empty
                                <p class="text-sm text-zinc-500">{{ __('No active units for this product.') }}</p>
                            @endforelse
                        </div>

                        <div class="flex justify-end gap-2">
                            <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                            <flux:button variant="primary" type="submit">{{ __('Add') }}</flux:button>
                        </div>
                    </form>
                @endif
            </flux:modal>
        </div>
    @endvolt
</x-layouts::app>
