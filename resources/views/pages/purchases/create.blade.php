<?php

use App\Actions\Purchases\CreatePurchaseOrder;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Supplier;
use Flux\Flux;
use Livewire\WithPagination;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

name('purchases.create');
middleware('auth');
middleware('verified');
uses(WithPagination::class);

state([
    'supplier_id' => null,
    'notes' => '',
    'search' => '',
    'cart' => [],
    'showUnitModal' => false,
    'selectedProduct' => null,
    'selectedUnitId' => null,
    'discount_minor' => 0,
    'client_request_id' => null,
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

$subtotalMinor = computed(fn () => collect($this->cart)->sum(fn ($item) => $item['subtotal_minor']));
$totalMinor = computed(fn () => $this->subtotalMinor - $this->discount_minor);

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
            $item['subtotal_minor'] = $item['quantity'] * $item['unit_price_minor'];
            $this->showUnitModal = false;
            return;
        }
    }

    $unitPriceMinor = (int) round(($unit->purchase_price_minor ?? $product->buy_price_minor ?: 0));

    $this->cart[] = [
        'cart_key' => $cartKey,
        'product_id' => $product->id,
        'product_unit_id' => $unit->id,
        'unit_name' => $unit->name,
        'unit_abbreviation' => $unit->abbreviation,
        'conversion_factor' => (float) $unit->conversion_factor,
        'name' => $product->name,
        'unit_price_minor' => $unitPriceMinor,
        'quantity' => 1,
        'subtotal_minor' => $unitPriceMinor,
        'discount_minor' => 0,
    ];

    $this->showUnitModal = false;
    $this->selectedProduct = null;
};

$updateQty = function ($index, $qty) {
    if (isset($this->cart[$index])) {
        $this->cart[$index]['quantity'] = max(1, (int) $qty);
        $this->cart[$index]['subtotal_minor'] = $this->cart[$index]['quantity'] * $this->cart[$index]['unit_price_minor'];
    }
};

$updatePrice = function ($index, $price) {
    if (isset($this->cart[$index])) {
        $this->cart[$index]['unit_price_minor'] = (int) max(0, round($price * 100));
        $this->cart[$index]['subtotal_minor'] = $this->cart[$index]['quantity'] * $this->cart[$index]['unit_price_minor'];
    }
};

$updateItemDiscount = function ($index, $discount) {
    if (isset($this->cart[$index])) {
        $this->cart[$index]['discount_minor'] = (int) max(0, round($discount * 100));
        $this->cart[$index]['subtotal_minor'] = ($this->cart[$index]['unit_price_minor'] - $this->cart[$index]['discount_minor']) * $this->cart[$index]['quantity'];
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

    $items = array_map(function ($item) {
        return [
            'product_id' => $item['product_id'],
            'product_unit_id' => $item['product_unit_id'],
            'quantity' => $item['quantity'],
            'unit_price_minor' => $item['unit_price_minor'],
            'discount_minor' => $item['discount_minor'] ?? 0,
        ];
    }, $this->cart);

    try {
        (new CreatePurchaseOrder())->handle([
            'supplier_id' => $this->supplier_id,
            'user_id' => auth()->id(),
            'items' => $items,
            'notes' => $this->notes,
            'discount_minor' => $this->discount_minor,
            'status' => $status,
            'client_request_id' => $this->client_request_id ?? (string) \Illuminate\Support\Str::uuid(),
            'location_id' => auth()->user()?->location_id,
            'device_id' => auth()->user()?->device_id,
            'enqueue_for_sync' => true,
        ]);
    } catch (\Illuminate\Database\QueryException $e) {
        if ($this->isIdempotencyViolation($e)) {
            Flux::toast(variant: 'success', text: __('Purchase order already exists.'));
            $this->redirect('/purchases', navigate: true);
            return;
        }
        throw $e;
    }

    Flux::toast(variant: 'success', text: __('Purchase order created.'));
    $this->redirect('/purchases', navigate: true);
};

$isIdempotencyViolation = function (\Illuminate\Database\QueryException $e): bool {
    $code = (int) ($e->errorInfo[1] ?? 0);
    return $code === 1062 || $code === 1555 || str_contains($e->getMessage(), 'client_request_id');
};

?>

<x-layouts::app :title="__('Create Purchase Order')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="{{ route('dashboard') }}">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item href="/purchases">{{ __('Purchase Orders') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Create') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="xl">{{ __('Create Purchase Order') }}</flux:heading>
                    <flux:subheading>{{ __('Add products and save as draft or submit') }}</flux:subheading>
                </div>
            </div>

            <div class="grid lg:grid-cols-3 gap-6">
                {{-- Product Search & Cart --}}
                <div class="lg:col-span-2 flex flex-col gap-4">
                    <flux:input size="md" wire:model.live.debounce.300ms="search" type="search"
                        placeholder="{{ __('Search products (name, SKU, barcode)...') }}" />

                    <div class="flex flex-wrap gap-2">
                        @foreach ($this->cart as $index => $item)
                            <flux:card class="w-64 flex-shrink-0">
                                <div class="p-3">
                                    <p class="font-medium truncate">{{ $item['name'] }}</p>
                                    <p class="text-xs text-zinc-500">{{ $item['unit_abbreviation'] }}</p>
                                    <div class="flex items-center gap-2 mt-2">
                                        <flux:input wire:model="cart.{{ $index }}.quantity" type="number" min="1" step="1"
                                            class="w-16" wire:change="updateQty({{ $index }}, \$event.target.value)" />
                                        <flux:input wire:model="cart.{{ $index }}.unit_price_minor" type="number" step="1" min="0"
                                            class="w-24" wire:change="updatePrice({{ $index }}, \$event.target.value)" />
                                        <flux:button variant="ghost" size="sm" icon="trash" class="text-red-500"
                                            wire:click="removeItem({{ $index }})" />
                                    </div>
                                    <p class="text-xs text-zinc-500 mt-1">
                                        Subtotal: {{ Number::currency($item['subtotal_minor'], 'IDR', 'id') }}
                                    </p>
                                </div>
                            </flux:card>
                        @endforeach
                    </div>

                    @if (empty($this->cart))
                        <p class="text-zinc-500 text-center py-8">{{ __('No products added yet. Search and add products to the cart.') }}</p>
                    @endif
                </div>

                {{-- Order Summary --}}
                <div class="space-y-4">
                    <flux:card>
                        <div class="p-4 space-y-4">
                            <flux:select wire:model="supplier_id" label="{{ __('Supplier') }}" searchable required>
                                <option value="">{{ __('Select supplier') }}</option>
                                @foreach ($this->supplierOptions as $supplier)
                                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                @endforeach
                            </flux:select>

                            <flux:textarea wire:model="notes" label="{{ __('Notes') }}" rows="3" />

                            <div class="grid grid-cols-2 gap-4 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                                <div>
                                    <span class="text-zinc-500 text-sm">{{ __('Subtotal') }}</span>
                                    <div class="font-mono text-lg">{{ Number::currency($this->subtotalMinor, 'IDR', 'id') }}</div>
                                </div>
                                <div>
                                    <span class="text-zinc-500 text-sm">{{ __('Discount') }}</span>
                                    <flux:input wire:model="discount_minor" type="number" step="1" min="0"
                                        class="w-full" placeholder="0" />
                                </div>
                            </div>

                            <div class="text-right pt-4 border-t border-zinc-200 dark:border-zinc-700">
                                <span class="text-zinc-500">{{ __('Total') }}</span>
                                <div class="font-mono text-xl text-green-600 dark:text-green-400">
                                    {{ Number::currency($this->totalMinor, 'IDR', 'id') }}
                                </div>
                            </div>

                            <div class="flex gap-2 pt-4">
                                <flux:button variant="filled" wire:click="saveDraft" class="flex-1" :disabled="empty($cart)">
                                    {{ __('Save Draft') }}
                                </flux:button>
                                <flux:button variant="primary" wire:click="submitPO" class="flex-1" :disabled="empty($cart)">
                                    {{ __('Submit PO') }}
                                </flux:button>
                            </div>
                        </div>
                    </flux:card>
                </div>
            </div>

            {{-- Unit Selector Modal --}}
            <flux:modal wire:model.self="showUnitModal" class="max-w-md">
                <div class="space-y-4">
                    <flux:heading size="lg">{{ __('Select Unit') }}</flux:heading>
                    @if ($this->selectedProduct)
                        <p class="font-medium">{{ $this->selectedProduct->name }}</p>
                        <div class="grid grid-cols-2 gap-2 max-h-60 overflow-y-auto">
                            @foreach ($this->selectedProduct->units as $unit)
                                @if ($unit->is_active)
                                    <flux:button variant="outline" class="w-full"
                                        wire:click="$set('selectedUnitId', {{ $unit->id }})"
                                        wire:click="addToCart">
                                        {{ $unit->name }} ({{ $unit->abbreviation }})
                                        @if ($unit->purchase_price_minor > 0)
                                            <span class="ml-2 text-xs text-zinc-500">
                                                {{ Number::currency($unit->purchase_price_minor, 'IDR', 'id') }}
                                            </span>
                                        @elseif ($this->selectedProduct->buy_price_minor > 0)
                                            <span class="ml-2 text-xs text-zinc-500">
                                                {{ Number::currency($this->selectedProduct->buy_price_minor, 'IDR', 'id') }}
                                            </span>
                                        @endif
                                    </flux:button>
                                @endif
                            @endforeach
                        </div>
                    @endif
                    <div class="flex justify-end">
                        <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    </div>
                </div>
            </flux:modal>
        </div>
    @endvolt
</x-layouts::app>