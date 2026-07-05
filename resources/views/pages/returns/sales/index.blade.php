<?php

use App\Models\SalesReturn;
use App\Models\Transaction;
use Flux\Flux;
use Livewire\WithPagination;
use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

name('returns.sales.index');
middleware('auth');
middleware('verified');
uses(WithPagination::class);

state([
    'search' => '',
    'showCreateModal' => false,
    'txSearch' => '',
    'selectedTransaction' => null,
    'returnItems' => [],
    'returnReason' => '',
]);

$returns = computed(function () {
    return SalesReturn::with('customer', 'user')
        ->whereHas('customer', fn ($q) => $q->where('name', 'like', '%' . $this->search . '%'))
        ->orWhere('return_number', 'like', '%' . $this->search . '%')
        ->orderByDesc('created_at')
        ->paginate(20);
});

$searchTransaction = function () {
    if (empty($this->txSearch)) {
        $this->selectedTransaction = null;
        $this->returnItems = [];
        return;
    }
    $tx = Transaction::with('items.product', 'items.productUnit')
        ->where('invoice_number', $this->txSearch)
        ->first();
    if ($tx) {
        $this->selectedTransaction = $tx;
        $this->returnItems = $tx->items->map(fn ($item) => [
            'transaction_item_id' => $item->id,
            'product_id' => $item->product_id,
            'product_unit_id' => $item->product_unit_id,
            'name' => $item->product?->name . ($item->unit_name ? " ({$item->unit_name})" : ''),
            'max_qty' => $item->qty,
            'qty' => 0,
            'price' => $item->price,
            'reason' => '',
        ])->toArray();
    } else {
        $this->selectedTransaction = null;
        $this->returnItems = [];
    }
};

$updateQty = function ($index, $value) {
    if (isset($this->returnItems[$index])) {
        $this->returnItems[$index]['qty'] = min((int)$value, $this->returnItems[$index]['max_qty']);
    }
};

$saveReturn = function () {
    if (!$this->selectedTransaction || empty(array_filter($this->returnItems, fn ($i) => $i['qty'] > 0))) return;

    $items = array_filter($this->returnItems, fn ($i) => $i['qty'] > 0);
    $total = collect($items)->sum(fn ($i) => $i['qty'] * $i['price']);

    $lastId = SalesReturn::max('id') ?? 0;
    $salesReturn = SalesReturn::create([
        'return_number' => 'SR-' . str_pad($lastId + 1, 6, '0', STR_PAD_LEFT),
        'transaction_id' => $this->selectedTransaction->id,
        'customer_id' => $this->selectedTransaction->customer_id,
        'total' => $total,
        'reason' => $this->returnReason,
        'user_id' => auth()->id(),
    ]);

    foreach ($items as $item) {
        $salesReturn->items()->create([
            'transaction_item_id' => $item['transaction_item_id'],
            'product_id' => $item['product_id'],
            'product_unit_id' => $item['product_unit_id'],
            'qty' => $item['qty'],
            'price' => $item['price'],
            'subtotal' => $item['qty'] * $item['price'],
            'reason' => $item['reason'],
        ]);

        $product = \App\Models\Product::find($item['product_id']);
        if ($product) {
            $product->increment('stock', $item['qty'] * ($item['product_unit_id'] ? \App\Models\ProductUnit::find($item['product_unit_id'])?->conversion_factor ?? 1 : 1));
        }
    }

    $this->showCreateModal = false;
    $this->selectedTransaction = null;
    $this->returnItems = [];
    $this->returnReason = '';
    Flux::toast(variant: 'success', text: __('Retur penjualan saved.'));
};
?>

<x-layouts::app :title="__('Retur Penjualan')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Retur Penjualan') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="xl">{{ __('Retur Penjualan') }}</flux:heading>
                    <flux:subheading>{{ __('Customer returns') }}</flux:subheading>
                </div>
                <flux:button variant="primary" icon="plus" wire:click="$set('showCreateModal', true)">
                    {{ __('New Retur') }}
                </flux:button>
            </div>

            <flux:input size="md" wire:model.live="search" type="search" placeholder="{{ __('Search by return number or customer...') }}" />

            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <flux:table :paginate="$this->returns">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Return #') }}</flux:table.column>
                        <flux:table.column>{{ __('Date') }}</flux:table.column>
                        <flux:table.column>{{ __('Customer') }}</flux:table.column>
                        <flux:table.column>{{ __('Total') }}</flux:table.column>
                        <flux:table.column>{{ __('Reason') }}</flux:table.column>
                        <flux:table.column>{{ __('User') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->returns as $r)
                            <flux:table.row :key="$r->id">
                                <flux:table.cell class="font-mono text-xs font-medium">{{ $r->return_number }}</flux:table.cell>
                                <flux:table.cell class="text-xs text-zinc-500">{{ $r->created_at->format('d M H:i') }}</flux:table.cell>
                                <flux:table.cell class="font-medium">{{ $r->customer?->name }}</flux:table.cell>
                                <flux:table.cell class="font-mono">Rp {{ number_format($r->total, 0, ',', '.') }}</flux:table.cell>
                                <flux:table.cell class="max-w-[200px] truncate text-xs text-zinc-500">{{ $r->reason ?: '-' }}</flux:table.cell>
                                <flux:table.cell class="text-xs text-zinc-500">{{ $r->user?->name }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>

            {{-- Create Modal --}}
            <flux:modal wire:model.self="showCreateModal" class="max-w-3xl">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('New Retur Penjualan') }}</flux:heading>
                        <flux:subheading>{{ __('Search transaction by invoice number.') }}</flux:subheading>
                    </div>

                    <flux:input wire:model.live.debounce="txSearch" type="search"
                        placeholder="{{ __('Invoice number...') }}" />

                    @if ($this->selectedTransaction)
                        <div class="rounded-lg border p-3 text-sm dark:border-zinc-700">
                            <strong>{{ __('Transaction') }}:</strong>
                            #{{ $this->selectedTransaction->invoice_number }}
                            - {{ $this->selectedTransaction->customer_name ?? __('Walk-in') }}
                            - Rp {{ number_format($this->selectedTransaction->total_amount, 0, ',', '.') }}
                        </div>

                        <div class="overflow-x-auto rounded-lg border dark:border-zinc-700">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b bg-zinc-50 text-left text-xs font-medium dark:border-zinc-700 dark:bg-zinc-800">
                                        <th class="px-3 py-2">{{ __('Product') }}</th>
                                        <th class="px-3 py-2 text-center">{{ __('Max') }}</th>
                                        <th class="px-3 py-2 text-center">{{ __('Return Qty') }}</th>
                                        <th class="px-3 py-2">{{ __('Reason') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($this->returnItems as $index => $item)
                                        <tr class="border-b dark:border-zinc-800">
                                            <td class="px-3 py-2 font-medium">{{ $item['name'] }}</td>
                                            <td class="px-3 py-2 text-center font-mono text-xs">{{ $item['max_qty'] }}</td>
                                            <td class="px-3 py-2 text-center">
                                                <input type="number" min="0" max="{{ $item['max_qty'] }}"
                                                    value="{{ $item['qty'] }}"
                                                    wire:change="updateQty({{ $index }}, $event.target.value)"
                                                    class="w-20 rounded border border-zinc-300 px-2 py-1 text-center text-sm dark:border-zinc-600 dark:bg-zinc-700">
                                            </td>
                                            <td class="px-3 py-2">
                                                <input type="text" wire:model="returnItems.{{ $index }}.reason"
                                                    placeholder="{{ __('Reason...') }}"
                                                    class="w-full rounded border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-600 dark:bg-zinc-700">
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <flux:input wire:model="returnReason" placeholder="{{ __('Overall return reason...') }}" />
                    @else
                        @if ($this->txSearch && empty($this->selectedTransaction))
                            <p class="text-sm text-red-500">{{ __('Transaction not found.') }}</p>
                        @endif
                    @endif

                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                        <flux:button variant="primary" wire:click="saveReturn"
                            :disabled="empty($this->selectedTransaction) || empty(array_filter($this->returnItems ?? [], fn ($i) => ($i['qty'] ?? 0) > 0))">
                            {{ __('Save Retur') }}
                        </flux:button>
                    </div>
                </div>
            </flux:modal>
        </div>
    @endvolt
</x-layouts::app>
