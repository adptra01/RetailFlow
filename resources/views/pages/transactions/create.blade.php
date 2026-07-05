<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\WithPagination;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\usesPagination;


usesPagination();

name('transactions.create');

middleware('auth');
middleware('verified');

state([
    'customer' => '',
    'category_id' => (int) '',
    'productSearch' => '',
    'cart' => [],
    'payment_method' => 'cash',
    'paid_amount' => 0,
    'notes' => '',
    'showConfirmModal' => false,
]);


$categoryOptions = computed(function () {
    return Category::orderBy('name')->get();
});

$products = computed(function () {
    $query = Product::query()->where('is_active', true);

    if (!empty($this->category_id)) {
        $query->where('category_id', $this->category_id);
    }

    if (!empty($this->productSearch)) {
        $query->where(function ($q) {
            $q->where('name', 'like', '%' . $this->productSearch . '%')->orWhere('sku', 'like', '%' . $this->productSearch . '%');
        });
    }

    return $query->orderBy('name')->paginate(12);
});

$totalAmount = computed(function () {
    return collect($this->cart)->sum('subtotal');
});

$changeAmount = computed(function () {
    $paid = (float) ($this->paid_amount ?: 0);
    $total = (float) ($this->totalAmount ?: 0);

    // Langsung kembalikan hasil pengurangan
    return $paid - $total;
});

$addToCart = function ($productId) {
    $product = Product::findOrFail($productId);

    foreach ($this->cart as &$item) {
        if ($item['product_id'] === $productId) {
            $item['quantity']++;
            $item['subtotal'] = $item['quantity'] * $item['unit_price'];
            return;
        }
    }

    $this->cart[] = [
        'product_id' => $product->id,
        'name' => $product->name,
        'unit_price' => (float) $product->price,
        'quantity' => 1,
        'subtotal' => (float) $product->price,
    ];
};

$incrementQty = function ($index) {
    if (isset($this->cart[$index])) {
        $this->cart[$index]['quantity']++;
        $this->cart[$index]['subtotal'] = $this->cart[$index]['quantity'] * $this->cart[$index]['unit_price'];
    }
};

$decrementQty = function ($index) {
    if (isset($this->cart[$index])) {
        if ($this->cart[$index]['quantity'] <= 1) {
            array_splice($this->cart, $index, 1);
        } else {
            $this->cart[$index]['quantity']--;
            $this->cart[$index]['subtotal'] = $this->cart[$index]['quantity'] * $this->cart[$index]['unit_price'];
        }
    }
};

$removeFromCart = function ($index) {
    array_splice($this->cart, $index, 1);
};

$confirmSave = function () {
    if (empty($this->cart)) {
        Flux::toast(variant: 'error', text: __('Please add at least one product.'));
        return;
    }

    $this->validate([
        'paid_amount' => 'required|numeric|min:0',
        'payment_method' => 'required|string|in:cash,transfer,debit_card,credit_card',
        'notes' => 'nullable|string|max:500',
    ]);

    if (($this->paid_amount ?? 0) < $this->totalAmount) {
        Flux::toast(variant: 'error', text: __('Paid amount is less than total.'));
        return;
    }

    $this->showConfirmModal = true;
};

$save = function () {
    $invoiceNumber = 'INV-' . now()->format('YmdHis') . '-' . strtoupper(Str::random(6));

    $transaction = Transaction::create([
        'customer' => $this->customer ?: fake()->name,
        'invoice_number' => $invoiceNumber,
        'total_amount' => $this->totalAmount,
        'paid_amount' => $this->paid_amount,
        'change_amount' => $this->changeAmount,
        'payment_method' => $this->payment_method,
        'notes' => $this->notes ?? '',
    ]);

    $items = [];
    foreach ($this->cart as $item) {
        $items[] = [
            'transaction_id' => $transaction->id,
            'product_id' => $item['product_id'],
            'quantity' => $item['quantity'],
            'unit_price' => $item['unit_price'],
            'subtotal' => $item['subtotal'],
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
    TransactionItem::insert($items);

    $this->reset(['customer', 'category_id', 'productSearch', 'cart', 'paid_amount', 'payment_method', 'notes', 'showConfirmModal']);

    Flux::toast(variant: 'success', text: __('Transaction #:invoice created.', ['invoice' => $invoiceNumber]));

    $this->redirect('/transactions/' . $transaction->id . '/receipt', navigate: true);
};

?>

<x-layouts::app :title="__('New Transaction')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            @php
                $methodLabels = [
                    'cash' => __('Cash'),
                    'transfer' => __('Transfer'),
                    'debit_card' => __('Debit Card'),
                    'credit_card' => __('Credit Card'),
                ];
                $paymentMethods = ['cash', 'transfer', 'debit_card', 'credit_card'];
            @endphp

            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item href="{{ route('transactions.index') }}">{{ __('Transactions') }}
                </flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('New Transaction') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div>
                <flux:heading size="xl">{{ __('New Transaction') }}</flux:heading>
                <flux:subheading>{{ __('Select products and process payment.') }}</flux:subheading>
            </div>

            <form wire:submit="confirmSave">
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {{-- LEFT COLUMN (2/3): Product Selection --}}
                    <div class="space-y-6 lg:col-span-2">
                        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                            <div class="mb-6">
                                <flux:heading size="lg">{{ __('Select Products') }}</flux:heading>
                                <flux:subheading>{{ __('Search and choose products to add to the transaction.') }}
                                </flux:subheading>
                            </div>

                            <div class="mb-4 grid gap-4 sm:grid-cols-2">
                                <flux:select wire:model.live="category_id" :label="__('Category')"
                                    placeholder="{{ __('All categories') }}">
                                    @foreach ($this->categoryOptions as $category)
                                        <flux:select.option value="{{ $category->id }}">{{ $category->name }}
                                        </flux:select.option>
                                    @endforeach
                                </flux:select>

                                <flux:input wire:model.live="productSearch" :label="__('Search')" type="search"
                                    placeholder="{{ __('Search by name or SKU...') }}" />
                            </div>

                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                @forelse ($this->products as $product)
                                    <div
                                        class="group relative flex items-end justify-between rounded-lg border border-zinc-200 p-3 transition-all hover:scale-[1.02] hover:border-zinc-400 hover:shadow-sm active:scale-[0.98] dark:border-zinc-700 dark:hover:border-zinc-500"
                                        style="background-image: linear-gradient(to top, rgba(0,0,0,0.7) 0%, rgba(0,0,0,0.1) 60%, rgba(0,0,0,0.05) 100%), url('{{ $product->image_url }}'); background-size: cover; background-position: center; min-height: 100px;">
                                        <div class="min-w-0 flex-1 z-10">
                                            <p class="truncate text-sm font-medium text-white drop-shadow-sm">{{ $product->name }}</p>
                                            <p class="text-xs text-zinc-200 drop-shadow-sm">
                                                {{ Number::currency($product->price, 'IDR', 'id') }}</p>
                                            <div class="mt-1">
                                            @if($product->is_unlimited_stock)
                                                <flux:badge size="xs" color="purple" inset="top bottom">{{ __('Tanpa Stok') }}</flux:badge>
                                            @elseif ($product->stock < 1)
                                                <flux:badge size="xs" color="red" inset="top bottom">
                                                    {{ __('out of stock') }}
                                                </flux:badge>
                                            @elseif ($product->stock <= 5)
                                                <flux:badge size="xs" color="orange" inset="top bottom">
                                                    {{ $product->stock }}
                                                    {{ __('left') }}</flux:badge>
                                            @endif
                                            </div>
                                        </div>
                                        <flux:button size="xs" variant="primary" icon="plus"
                                            wire:click="addToCart({{ $product->id }})" :disabled="!$product->is_unlimited_stock && $product->stock < 1"
                                            class="shrink-0 z-10" />
                                    </div>
                                @empty
                                    <div
                                        class="col-span-full rounded-lg border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-600">
                                        {{ __('No products found.') }}
                                    </div>
                                @endforelse
                            </div>

                            <div class="mt-4">
                                {{ $this->products->links(data: ['navigate' => true]) }}
                            </div>
                        </div>
                    </div>

                    {{-- RIGHT COLUMN (1/3): Checkout Panel --}}
                    <div class="space-y-5 lg:col-span-1">
                        <div class="sticky top-6 space-y-5">
                            {{-- Order Summary --}}
                            <div
                                class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                                <flux:heading size="lg">{{ __('Order Summary') }}</flux:heading>

                                <div class="mt-4 space-y-4">
                                    {{-- Customer --}}
                                    <flux:input wire:model="customer" :label="__('Customer')"
                                        placeholder="{{ __('Walk-in customer') }}" />

                                    {{-- Cart Items --}}
                                    @if (count($this->cart) > 0)
                                        <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                            @foreach ($this->cart as $index => $item)
                                                <div
                                                    class="flex items-center justify-between gap-2 py-2 first:pt-0 last:pb-0">
                                                    <div class="min-w-0 flex-1">
                                                        <p class="truncate text-sm font-medium">{{ $item['name'] }}</p>
                                                        <p class="text-xs text-zinc-500">
                                                            {{ Number::currency($item['unit_price'], 'IDR', 'id') }}</p>
                                                    </div>
                                                    <div class="flex items-center gap-1">
                                                        <flux:button size="xs" variant="ghost" icon="minus"
                                                            wire:click="decrementQty({{ $index }})"
                                                            class="shrink-0" />
                                                        <span
                                                            class="w-5 text-center text-sm font-medium">{{ $item['quantity'] }}</span>
                                                        <flux:button size="xs" variant="ghost" icon="plus"
                                                            wire:click="incrementQty({{ $index }})"
                                                            class="shrink-0" />
                                                    </div>
                                                    <div class="min-w-[4.5rem] text-right text-sm font-medium">
                                                        {{ Number::currency($item['subtotal'], 'IDR', 'id') }}
                                                    </div>
                                                    <button type="button" wire:click="removeFromCart({{ $index }})"
                                                        class="shrink-0 rounded-full p-1 text-zinc-400 transition-colors hover:bg-red-50 hover:text-red-500 dark:hover:bg-red-900/20">
                                                        <flux:icon name="x-mark" class="h-3.5 w-3.5" />
                                                    </button>
                                                </div>
                                            @endforeach
                                        </div>

                                        <div
                                            class="flex items-center justify-between border-t border-zinc-200 pt-3 text-base font-bold dark:border-zinc-700">
                                            <span>{{ __('Total') }}</span>
                                            <span
                                                class="text-blue-600 dark:text-blue-400">{{ Number::currency($this->totalAmount, 'IDR', 'id') }}</span>
                                        </div>
                                    @else
                                        <div
                                            class="rounded-lg border border-dashed border-zinc-300 p-4 text-center text-sm text-zinc-500 dark:border-zinc-600">
                                            {{ __('No products added yet.') }}
                                        </div>
                                    @endif
                                </div>
                            </div>

                            {{-- Payment --}}
                            <div
                                class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                                <flux:heading size="lg">{{ __('Payment') }}</flux:heading>
                                <div class="mt-4 space-y-3">
                                    <flux:select wire:model="payment_method" :label="__('Method')">
                                        @foreach ($paymentMethods as $method)
                                            <flux:select.option value="{{ $method }}">{{ $methodLabels[$method] }}
                                            </flux:select.option>
                                        @endforeach
                                    </flux:select>

                                    <flux:input wire:model.live="paid_amount" :label="__('Paid Amount')" type="number"
                                        step="0.01" min="0" />

                                    <div>
                                        <flux:label>{{ __('Change') }}</flux:label>

                                        <!-- Menggunakan @class untuk kondisi warna -->
                                        <div @class([
                                            'mt-1 text-lg font-bold',
                                            'text-green-600 dark:text-green-400' => $this->changeAmount >= 0,
                                            'text-red-600 dark:text-red-400' => $this->changeAmount < 0,
                                        ])>
                                            {{ Number::currency($this->changeAmount, 'IDR', 'id') }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Notes --}}
                            <div
                                class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                                <flux:textarea wire:model="notes" :label="__('Notes')"
                                    placeholder="{{ __('Optional...') }}" rows="2" />
                            </div>

                            {{-- Actions --}}
                            <div class="flex gap-2">
                                <flux:button href="{{ route('transactions.index') }}" variant="filled"
                                    class="flex-1 justify-center">
                                    {{ __('Cancel') }}
                                </flux:button>
                                <flux:button type="submit" variant="primary" :disabled="empty($this->cart)"
                                    class="flex-1 justify-center">
                                    {{ __('Review & Confirm') }}
                                </flux:button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

            {{-- Confirmation Modal --}}
            <flux:modal wire:model.self="showConfirmModal" class="max-w-2xl">
                <form wire:submit="save" class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Confirm Transaction') }}</flux:heading>
                        <flux:subheading>{{ __('Please review the transaction details below before saving.') }}
                        </flux:subheading>
                    </div>

                    @if ($customer)
                        <div class="text-sm">
                            <span class="text-zinc-500">{{ __('Customer') }}:</span>
                            <span class="ml-2 font-medium">{{ $customer }}</span>
                        </div>
                    @endif

                    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                                    <th class="px-3 py-2 text-left font-medium">{{ __('Product') }}</th>
                                    <th class="px-3 py-2 text-center font-medium">{{ __('Qty') }}</th>
                                    <th class="px-3 py-2 text-right font-medium">{{ __('Price') }}</th>
                                    <th class="px-3 py-2 text-right font-medium">{{ __('Subtotal') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->cart as $item)
                                    <tr class="border-b border-zinc-100 last:border-b-0 dark:border-zinc-800">
                                        <td class="px-3 py-2">{{ $item['name'] }}</td>
                                        <td class="px-3 py-2 text-center">{{ $item['quantity'] }}</td>
                                        <td class="px-3 py-2 text-right">
                                            {{ Number::currency($item['unit_price'], 'IDR', 'id') }}</td>
                                        <td class="px-3 py-2 text-right font-medium">
                                            {{ Number::currency($item['subtotal'], 'IDR', 'id') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="border-t bg-zinc-50 font-semibold dark:border-zinc-700 dark:bg-zinc-800">
                                    <td colspan="3" class="px-3 py-2 text-right">{{ __('Total') }}</td>
                                    <td class="px-3 py-2 text-right">
                                        {{ Number::currency($this->totalAmount, 'IDR', 'id') }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div class="grid grid-cols-3 gap-4 text-sm">
                        <div>
                            <span class="text-zinc-500">{{ __('Payment') }}</span>
                            <p class="font-medium">{{ $methodLabels[$payment_method] ?? $payment_method }}</p>
                        </div>
                        <div>
                            <span class="text-zinc-500">{{ __('Paid') }}</span>
                            <p class="font-medium">{{ Number::currency((float) ($paid_amount ?: 0), 'IDR', 'id') }}</p>
                        </div>
                        <div>
                            <span class="text-zinc-500">{{ __('Change') }}</span>
                            <p class="font-medium">{{ Number::currency($this->changeAmount, 'IDR', 'id') }}</p>
                        </div>
                    </div>

                    @if ($notes)
                        <div class="text-sm">
                            <span class="text-zinc-500">{{ __('Notes') }}:</span>
                            <p class="mt-1">{{ $notes }}</p>
                        </div>
                    @endif

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="primary" type="submit">{{ __('Confirm & Save') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        </div>
    @endvolt
</x-layouts::app>
