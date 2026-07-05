<?php

use App\Models\Transaction;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\WithPagination;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

name('transactions.index');

middleware('auth');
middleware('verified');

uses(WithPagination::class);

state([
    'search' => '',
    'sortBy' => 'id',
    'sortDirection' => 'desc',
])->url();

state([
    'showDetailModal' => false,
    'showDeleteModal' => false,
    'deletingTransactionId' => null,
    'viewingTransactionId' => null,
]);

$sort = function ($column) {
    if ($this->sortBy === $column) {
        $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
    } else {
        $this->sortBy = $column;
        $this->sortDirection = 'asc';
    }
};

$transactions = computed(function () {
    return Transaction::query()
        ->withCount('items')
        ->where(function ($q) {
            $q->where('invoice_number', 'like', '%' . $this->search . '%')->orWhere('customer', 'like', '%' . $this->search . '%');
        })
        ->orderBy($this->sortBy, $this->sortDirection)
        ->paginate(10);
});

$viewTransaction = computed(function () {
    if (!$this->viewingTransactionId) {
        return null;
    }

    return Transaction::with('items.product')->find($this->viewingTransactionId);
});

$viewDetail = function ($id) {
    $this->viewingTransactionId = $id;
    $this->showDetailModal = true;
};

$confirmDelete = function ($id) {
    $this->deletingTransactionId = $id;
    $this->showDeleteModal = true;
};

$delete = function () {
    $transaction = Transaction::findOrFail($this->deletingTransactionId);
    $transaction->items()->delete();
    $transaction->delete();

    $this->deletingTransactionId = null;
    $this->showDeleteModal = false;

    Flux::toast(variant: 'success', text: __('Transaction deleted.'));
};

$methodLabels = [
    'cash' => 'Tunai',
    'transfer' => 'Transfer',
    'debit_card' => 'Kartu Debit',
    'credit_card' => 'Kartu Kredit',
];

$methodColors = [
    'cash' => 'green',
    'transfer' => 'blue',
    'debit_card' => 'purple',
    'credit_card' => 'orange',
];

?>

<x-layouts::app :title="__('Transactions')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Transactions') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="xl">{{ __('Transactions') }}</flux:heading>
                    <flux:subheading>{{ __('Manage sales transactions.') }}</flux:subheading>
                </div>
                <flux:button variant="primary" icon="plus" href="{{ route('transactions.create') }}" >
                    {{ __('New Transaction') }}
                </flux:button>
            </div>

            <flux:input size="md" wire:model.live="search" type="search"
                placeholder="{{ __('Search by invoice or customer...') }}" />

            <div
                class="relative h-full flex-1 overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700 p-6">

                <flux:table :paginate="$this->transactions">
                    <flux:table.columns>
                        <flux:table.column sortable :sorted="$sortBy === 'id'" :direction="$sortDirection"
                            wire:click="sort('id')">
                            {{ __('Invoice') }}
                        </flux:table.column>

                        <flux:table.column>
                            {{ __('Customer') }}
                        </flux:table.column>

                        <flux:table.column>
                            {{ __('Items') }}
                        </flux:table.column>

                        <flux:table.column sortable :sorted="$sortBy === 'total_amount'" :direction="$sortDirection"
                            wire:click="sort('total_amount')">
                            {{ __('Total') }}
                        </flux:table.column>

                        <flux:table.column>
                            {{ __('Payment') }}
                        </flux:table.column>

                        <flux:table.column>
                            {{ __('Actions') }}
                        </flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->transactions as $transaction)
                            <flux:table.row :key="$transaction->id">
                                <flux:table.cell>
                                    <div class="flex flex-col">
                                        <span class="font-medium">{{ $transaction->invoice_number }}</span>
                                        <span
                                            class="text-xs text-zinc-500">{{ $transaction->created_at->format('d M Y, H:i') }}</span>
                                    </div>
                                </flux:table.cell>

                                <flux:table.cell>
                                    {{ $transaction->customer ?: '-' }}
                                </flux:table.cell>

                                <flux:table.cell>
                                    <flux:badge size="sm" inset="top bottom">
                                        {{ $transaction->items_count }} {{ __('item') }}
                                    </flux:badge>
                                </flux:table.cell>

                                <flux:table.cell variant="strong">
                                    {{ Number::currency($transaction->total_amount, 'IDR', 'id') }}
                                </flux:table.cell>

                                <flux:table.cell>
                                    <flux:badge size="sm"
                                        :color="$methodColors[$transaction->payment_method] ?? 'gray'" inset="top bottom">
                                        {{ $methodLabels[$transaction->payment_method] ?? $transaction->payment_method }}
                                    </flux:badge>
                                </flux:table.cell>

                                <flux:table.cell>
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal"
                                            inset="top bottom" />
                                        <flux:menu>
                                            <flux:menu.item icon="eye" wire:click="viewDetail({{ $transaction->id }})">
                                                {{ __('View') }}
                                            </flux:menu.item>
                                            <flux:menu.item icon="pencil-square"
                                                href="{{ route('transactions.edit', ['transaction' => $transaction->id]) }}"
                                                >
                                                {{ __('Edit') }}
                                            </flux:menu.item>
                                            <flux:menu.item icon="printer"
                                                href="/transactions/{{ $transaction->id }}/receipt"
                                                target="_blank"
                                                >
                                                {{ __('Print') }}
                                            </flux:menu.item>
                                            <flux:menu.separator />
                                            <flux:menu.item icon="trash" variant="danger"
                                                wire:click="confirmDelete({{ $transaction->id }})">
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
            <flux:modal wire:model.self="showDetailModal" class="max-w-2xl">
                @if ($this->viewTransaction)
                    <div class="space-y-6">
                        <div class="flex items-start justify-between">
                            <div>
                                <flux:heading size="lg">{{ $this->viewTransaction->invoice_number }}</flux:heading>
                                <flux:subheading>
                                    {{ $this->viewTransaction->created_at->format('d M Y, H:i') }}
                                    &middot;
                                    {{ $this->viewTransaction->customer ?: '-' }}
                                </flux:subheading>
                            </div>
                            <flux:badge size="sm" inset="top bottom">
                                {{ $methodLabels[$this->viewTransaction->payment_method] ?? $this->viewTransaction->payment_method }}
                            </flux:badge>
                        </div>

                        <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                                        <th class="px-3 py-2 text-left font-medium">{{ __('Product') }}</th>
                                        <th class="px-3 py-2 text-right font-medium">{{ __('Price') }}</th>
                                        <th class="px-3 py-2 text-center font-medium">{{ __('Qty') }}</th>
                                        <th class="px-3 py-2 text-right font-medium">{{ __('Subtotal') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($this->viewTransaction->items as $item)
                                        <tr class="border-b border-zinc-100 last:border-b-0 dark:border-zinc-800">
                                            <td class="px-3 py-2">{{ $item->product?->name ?? '-' }}</td>
                                            <td class="px-3 py-2 text-right">
                                                {{ Number::currency($item->unit_price, 'IDR', 'id') }}</td>
                                            <td class="px-3 py-2 text-center">{{ $item->quantity }}</td>
                                            <td class="px-3 py-2 text-right font-medium">
                                                {{ Number::currency($item->subtotal, 'IDR', 'id') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="border-t bg-zinc-50 font-semibold dark:border-zinc-700 dark:bg-zinc-800">
                                        <td colspan="3" class="px-3 py-2 text-right">{{ __('Total') }}</td>
                                        <td class="px-3 py-2 text-right">
                                            {{ Number::currency($this->viewTransaction->total_amount, 'IDR', 'id') }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                            <div>
                                <span class="text-zinc-500">{{ __('Paid') }}</span>
                                <p class="font-medium">
                                    {{ Number::currency($this->viewTransaction->paid_amount, 'IDR', 'id') }}</p>
                            </div>
                            <div>
                                <span class="text-zinc-500">{{ __('Change') }}</span>
                                <p class="font-medium">
                                    {{ Number::currency($this->viewTransaction->change_amount, 'IDR', 'id') }}</p>
                            </div>
                            <div>
                                <span class="text-zinc-500">{{ __('Items') }}</span>
                                <p class="font-medium">{{ $this->viewTransaction->items->count() }}
                                    {{ __('item') }}</p>
                            </div>
                        </div>

                        @if ($this->viewTransaction->notes)
                            <div>
                                <span class="text-sm text-zinc-500">{{ __('Notes') }}</span>
                                <p class="mt-1 text-sm">{{ $this->viewTransaction->notes }}</p>
                            </div>
                        @endif

                        <div class="flex justify-end gap-2">
                            <flux:button variant="filled"
                                href="{{ route('transactions.edit', ['transaction' => $this->viewTransaction->id]) }}" >
                                {{ __('Edit Transaction') }}
                            </flux:button>
                            <flux:button variant="filled"
                                href="/transactions/{{ $this->viewTransaction->id }}/receipt"
                                target="_blank"
                                >
                                {{ __('Print Receipt') }}
                            </flux:button>
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
                    <div>
                        <flux:heading size="lg">{{ __('Delete Transaction') }}</flux:heading>
                        <flux:subheading>
                            {{ __('Are you sure you want to delete this transaction? All items in this transaction will also be deleted. This action cannot be undone.') }}
                        </flux:subheading>
                    </div>

                    <div class="flex justify-end gap-2">
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
