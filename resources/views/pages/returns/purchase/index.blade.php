<?php

use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use Flux\Flux;
use Livewire\WithPagination;
use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

name('returns.purchase.index');
middleware('auth');
middleware('verified');
uses(WithPagination::class);

state([
    'search' => '',
    'showCreateModal' => false,
    'poSearch' => '',
    'selectedPO' => null,
    'returnItems' => [],
    'returnReason' => '',
]);

$returns = computed(function () {
    return PurchaseReturn::with('supplier', 'user')
        ->whereHas('supplier', fn ($q) => $q->where('name', 'like', '%' . $this->search . '%'))
        ->orWhere('return_number', 'like', '%' . $this->search . '%')
        ->orderByDesc('created_at')
        ->paginate(20);
});

$searchPO = function () {
    if (empty($this->poSearch)) {
        $this->selectedPO = null;
        $this->returnItems = [];
        return;
    }
    $po = PurchaseOrder::with('items.product')
        ->where('order_number', $this->poSearch)
        ->first();
    if ($po) {
        $this->selectedPO = $po;
        $this->returnItems = $po->items->map(fn ($item) => [
            'product_id' => $item->product_id,
            'name' => $item->product?->name ?? '-',
            'max_qty' => $item->qty_received ?? $item->qty,
            'qty' => 0,
            'price' => $item->unit_price,
            'reason' => '',
        ])->toArray();
    } else {
        $this->selectedPO = null;
        $this->returnItems = [];
    }
};

$updateQty = function ($index, $value) {
    if (isset($this->returnItems[$index])) {
        $this->returnItems[$index]['qty'] = min((int)$value, $this->returnItems[$index]['max_qty']);
    }
};

$saveReturn = function () {
    if (!$this->selectedPO || empty(array_filter($this->returnItems, fn ($i) => $i['qty'] > 0))) return;

    $items = array_filter($this->returnItems, fn ($i) => $i['qty'] > 0);
    $total = collect($items)->sum(fn ($i) => $i['qty'] * $i['price']);

    $lastId = PurchaseReturn::max('id') ?? 0;
    $purchaseReturn = PurchaseReturn::create([
        'return_number' => 'PR-' . str_pad($lastId + 1, 6, '0', STR_PAD_LEFT),
        'purchase_order_id' => $this->selectedPO->id,
        'supplier_id' => $this->selectedPO->supplier_id,
        'total' => $total,
        'reason' => $this->returnReason,
        'user_id' => auth()->id(),
    ]);

    foreach ($items as $item) {
        $purchaseReturn->items()->create([
            'product_id' => $item['product_id'],
            'qty' => $item['qty'],
            'price' => $item['price'],
            'subtotal' => $item['qty'] * $item['price'],
            'reason' => $item['reason'],
        ]);

        $product = \App\Models\Product::find($item['product_id']);
        if ($product) {
            $product->decrement('stock', $item['qty']);
        }
    }

    $this->showCreateModal = false;
    $this->selectedPO = null;
    $this->returnItems = [];
    $this->returnReason = '';
    Flux::toast(variant: 'success', text: __('Retur pembelian saved.'));
};
?>

<x-layouts::app :title="__('Retur Pembelian')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Retur Pembelian') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="xl">{{ __('Retur Pembelian') }}</flux:heading>
                    <flux:subheading>{{ __('Returns to supplier') }}</flux:subheading>
                </div>
                <flux:button variant="primary" icon="plus" wire:click="$set('showCreateModal', true)">
                    {{ __('New Retur') }}
                </flux:button>
            </div>

            <flux:input size="md" wire:model.live="search" type="search" placeholder="{{ __('Search by return number or supplier...') }}" />

            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <flux:table :paginate="$this->returns">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Return #') }}</flux:table.column>
                        <flux:table.column>{{ __('Date') }}</flux:table.column>
                        <flux:table.column>{{ __('Supplier') }}</flux:table.column>
                        <flux:table.column>{{ __('Total') }}</flux:table.column>
                        <flux:table.column>{{ __('Reason') }}</flux:table.column>
                        <flux:table.column>{{ __('User') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->returns as $r)
                            <flux:table.row :key="$r->id">
                                <flux:table.cell class="font-mono text-xs font-medium">{{ $r->return_number }}</flux:table.cell>
                                <flux:table.cell class="text-xs text-zinc-500">{{ $r->created_at->format('d M H:i') }}</flux:table.cell>
                                <flux:table.cell class="font-medium">{{ $r->supplier?->name }}</flux:table.cell>
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
                        <flux:heading size="lg">{{ __('New Retur Pembelian') }}</flux:heading>
                        <flux:subheading>{{ __('Search PO by order number.') }}</flux:subheading>
                    </div>

                    <flux:input wire:model.live.debounce="poSearch" type="search"
                        placeholder="{{ __('PO number...') }}" />

                    @if ($this->selectedPO)
                        <div class="rounded-lg border p-3 text-sm dark:border-zinc-700">
                            <strong>{{ __('PO') }}:</strong>
                            #{{ $this->selectedPO->order_number }}
                            - {{ $this->selectedPO->supplier?->name }}
                            - Rp {{ number_format($this->selectedPO->total_amount, 0, ',', '.') }}
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
                        @if ($this->poSearch && empty($this->selectedPO))
                            <p class="text-sm text-red-500">{{ __('PO not found.') }}</p>
                        @endif
                    @endif

                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                        <flux:button variant="primary" wire:click="saveReturn"
                            :disabled="empty($this->selectedPO) || empty(array_filter($this->returnItems ?? [], fn ($i) => ($i['qty'] ?? 0) > 0))">
                            {{ __('Save Retur') }}
                        </flux:button>
                    </div>
                </div>
            </flux:modal>
        </div>
    @endvolt
</x-layouts::app>
