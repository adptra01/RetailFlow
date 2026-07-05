<?php

use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use Flux\Flux;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;

name('purchases.show');
middleware('auth');
middleware('verified');

state([
    'showReceiveModal' => false,
    'receiptQuantities' => [],
    'receiptNotes' => '',
]);

$statusColor = fn ($status) => match ($status) { 'draft' => 'zinc', 'pending' => 'amber', 'partial' => 'blue', 'received' => 'green', 'cancelled' => 'red', default => 'zinc' };

$po = computed(function () {
    return PurchaseOrder::with(['supplier', 'user', 'items.product', 'items.productUnit', 'goodsReceipts.user'])->findOrFail(request()->route('purchaseorder'));
});

$openReceive = function () {
    $this->receiptQuantities = $this->po->items->mapWithKeys(fn ($item) => [$item->id => $item->quantity - $item->received_quantity])->toArray();
    $this->showReceiveModal = true;
};

$receiveGoods = function () {
    $this->validate([
        'receiptQuantities.*' => 'required|integer|min:0',
        'receiptNotes' => 'nullable|string|max:500',
    ]);

    GoodsReceipt::create([
        'receipt_number' => 'GR-' . now()->format('YmdHis') . '-' . strtoupper(\Illuminate\Support\Str::random(6)),
        'purchase_order_id' => $this->po->id,
        'user_id' => auth()->id(),
        'notes' => $this->receiptNotes,
    ]);

    foreach ($this->receiptQuantities as $itemId => $qty) {
        if ($qty <= 0) continue;
        $item = $this->po->items()->findOrFail($itemId);
        $item->increment('received_quantity', $qty);

        $product = $item->product;
        if ($product && !$product->is_unlimited_stock) {
            $baseQty = $qty * ($item->productUnit?->conversion_factor ?? 1);
            $product->increment('stock', $baseQty);
        }
    }

    $fresh = $this->po->fresh();
    $allReceived = $fresh->items->every(fn ($i) => $i->received_quantity >= $i->quantity);
    $anyReceived = $fresh->items->sum('received_quantity') > 0;
    $fresh->update(['status' => $allReceived ? 'received' : ($anyReceived ? 'partial' : $fresh->status)]);

    $this->showReceiveModal = false;
    $this->receiptNotes = '';
    Flux::toast(variant: 'success', text: __('Goods received successfully.'));
};

$cancelPO = function () {
    $this->po->update(['status' => 'cancelled']);
    Flux::toast(variant: 'success', text: __('Purchase order cancelled.'));
};

?>

<x-layouts::app :title="__('Purchase Order')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item href="/purchases">{{ __('Purchase Orders') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ $this->po->order_number }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex items-start justify-between">
                <div>
                    <div class="flex items-center gap-3">
                        <flux:heading size="xl">{{ $this->po->order_number }}</flux:heading>
                        <flux:badge :color="$statusColor($this->po->status)" size="lg">{{ __(ucfirst($this->po->status)) }}</flux:badge>
                    </div>
                    <flux:subheading>{{ __('From') }} <strong>{{ $this->po->supplier?->name }}</strong> &middot; {{ $this->po->created_at->format('d M Y H:i') }}</flux:subheading>
                </div>
                <div class="flex gap-2">
                    @if (in_array($this->po->status, ['draft', 'pending', 'partial']))
                        <flux:button variant="primary" wire:click="openReceive" :disabled="$this->po->status === 'draft'">
                            {{ __('Receive Goods') }}
                        </flux:button>
                    @endif
                    @if (in_array($this->po->status, ['draft', 'pending']))
                        <flux:button variant="danger" wire:click="cancelPO" wire:confirm="{{ __('Cancel this order?') }}">
                            {{ __('Cancel') }}
                        </flux:button>
                    @endif
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="lg">{{ __('Order Items') }}</flux:heading>
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b text-left text-xs font-medium text-zinc-500 dark:border-zinc-700">
                                <th class="pb-3 pr-4">{{ __('Product') }}</th>
                                <th class="pb-3 pr-4">{{ __('Unit') }}</th>
                                <th class="pb-3 pr-4 text-right">{{ __('Quantity') }}</th>
                                <th class="pb-3 pr-4 text-right">{{ __('Received') }}</th>
                                <th class="pb-3 pr-4 text-right">{{ __('Price') }}</th>
                                <th class="pb-3 pr-4 text-right">{{ __('Subtotal') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->po->items as $item)
                                <tr class="border-b border-zinc-50 dark:border-zinc-800">
                                    <td class="py-3 pr-4 font-medium">{{ $item->product?->name ?? '-' }}</td>
                                    <td class="py-3 pr-4 text-xs text-zinc-500">{{ $item->productUnit?->abbreviation ?? 'PCS' }}</td>
                                    <td class="py-3 pr-4 text-right">{{ $item->quantity }}</td>
                                    <td class="py-3 pr-4 text-right">
                                        @if($item->received_quantity >= $item->quantity)
                                            <span class="text-green-600">{{ $item->received_quantity }}</span>
                                        @elseif($item->received_quantity > 0)
                                            <span class="text-amber-600">{{ $item->received_quantity }}</span>
                                        @else
                                            <span class="text-zinc-400">0</span>
                                        @endif
                                    </td>
                                    <td class="py-3 pr-4 text-right">{{ Number::currency($item->unit_price, 'IDR', 'id') }}</td>
                                    <td class="py-3 pr-4 text-right font-medium">{{ Number::currency($item->subtotal, 'IDR', 'id') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t font-semibold dark:border-zinc-700">
                                <td colspan="5" class="py-3 pr-4 text-right">{{ __('Total') }}</td>
                                <td class="py-3 pr-4 text-right">{{ Number::currency($this->po->total_amount, 'IDR', 'id') }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            @if ($this->po->notes)
                <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-xs font-medium uppercase tracking-wider text-zinc-500">{{ __('Notes') }}</p>
                    <p class="mt-2 text-sm">{{ $this->po->notes }}</p>
                </div>
            @endif

            @if ($this->po->goodsReceipts->count() > 0)
                <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:heading size="lg">{{ __('Goods Receipt History') }}</flux:heading>
                    <div class="mt-4 space-y-2">
                        @foreach ($this->po->goodsReceipts as $gr)
                            <div class="flex items-center justify-between rounded-lg border border-zinc-100 p-3 text-sm dark:border-zinc-700">
                                <div>
                                    <span class="font-mono text-xs font-medium">{{ $gr->receipt_number }}</span>
                                    <span class="ml-4 text-zinc-500">{{ $gr->created_at->format('d M Y H:i') }}</span>
                                </div>
                                <div class="text-xs text-zinc-500">{{ $gr->user?->name ?? '-' }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <flux:modal wire:model.self="showReceiveModal" class="max-w-2xl">
                <form wire:submit="receiveGoods" class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Receive Goods') }}</flux:heading>
                        <flux:subheading>{{ __('Enter the actual quantity received for each item.') }}</flux:subheading>
                    </div>

                    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                                    <th class="px-3 py-2 text-left font-medium">{{ __('Product') }}</th>
                                    <th class="px-3 py-2 text-center font-medium">{{ __('Ordered') }}</th>
                                    <th class="px-3 py-2 text-center font-medium">{{ __('Received') }}</th>
                                    <th class="px-3 py-2 text-center font-medium">{{ __('To Receive') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->po->items as $item)
                                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                        <td class="px-3 py-2">
                                            <p class="font-medium">{{ $item->product?->name }}</p>
                                            <p class="text-xs text-zinc-500">{{ $item->productUnit?->abbreviation ?? 'PCS' }}</p>
                                        </td>
                                        <td class="px-3 py-2 text-center">{{ $item->quantity }}</td>
                                        <td class="px-3 py-2 text-center">{{ $item->received_quantity }}</td>
                                        <td class="px-3 py-2 text-center">
                                            <input type="number" min="0" max="{{ $item->quantity - $item->received_quantity }}"
                                                wire:model="receiptQuantities.{{ $item->id }}"
                                                class="w-20 rounded border border-zinc-300 px-2 py-1 text-center text-sm dark:border-zinc-600 dark:bg-zinc-700">
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <flux:textarea wire:model="receiptNotes" :label="__('Notes')" placeholder="{{ __('Optional notes for this receipt...') }}" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                        <flux:button variant="primary" type="submit">{{ __('Confirm Receive') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        </div>
    @endvolt
</x-layouts::app>
