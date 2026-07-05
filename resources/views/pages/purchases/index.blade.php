<?php

use App\Models\PurchaseOrder;
use Livewire\WithPagination;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

name('purchases.index');
middleware('auth');
middleware('verified');
uses(WithPagination::class);

state(['search' => ''])->url();

$purchaseOrders = computed(function () {
    return PurchaseOrder::query()
        ->with('supplier', 'user')
        ->withCount('items')
        ->where(function ($q) {
            if ($this->search) {
                $q->where('order_number', 'like', '%' . $this->search . '%')
                  ->orWhereHas('supplier', fn ($q) => $q->where('name', 'like', '%' . $this->search . '%'));
            }
        })
        ->orderByDesc('created_at')
        ->paginate(10);
});

$statusColor = fn ($status) => match ($status) {
    'draft' => 'zinc',
    'pending' => 'amber',
    'partial' => 'blue',
    'received' => 'green',
    'cancelled' => 'red',
    default => 'zinc',
};

$statusLabel = fn ($status) => __(ucfirst($status));

?>

<x-layouts::app :title="__('Purchase Orders')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Purchase Orders') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="xl">{{ __('Purchase Orders') }}</flux:heading>
                    <flux:subheading>{{ __('Manage orders to suppliers') }}</flux:subheading>
                </div>
                <flux:button variant="primary" icon="plus" href="/purchases/create">
                    {{ __('New Purchase Order') }}
                </flux:button>
            </div>

            <flux:input size="md" wire:model.live="search" type="search"
                placeholder="{{ __('Search by order number or supplier...') }}" />

            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <flux:table :paginate="$this->purchaseOrders">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Order #') }}</flux:table.column>
                        <flux:table.column>{{ __('Supplier') }}</flux:table.column>
                        <flux:table.column>{{ __('Items') }}</flux:table.column>
                        <flux:table.column>{{ __('Total') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column>{{ __('Date') }}</flux:table.column>
                        <flux:table.column>{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->purchaseOrders as $po)
                            <flux:table.row :key="$po->id">
                                <flux:table.cell class="font-mono text-xs font-medium">{{ $po->order_number }}</flux:table.cell>
                                <flux:table.cell>{{ $po->supplier?->name ?? '-' }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" inset="top bottom">{{ $po->items_count }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell class="font-medium">{{ Number::currency($po->total_amount, 'IDR', 'id') }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge :color="$this->statusColor($po->status)" size="sm" inset="top bottom">
                                        {{ $this->statusLabel($po->status) }}
                                    </flux:badge>
                                </flux:table.cell>
                                <flux:table.cell class="text-xs text-zinc-500">{{ $po->created_at->format('d M Y') }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:button size="xs" variant="ghost" href="/purchases/{{ $po->id }}">
                                        {{ __('Detail') }}
                                    </flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>
    @endvolt
</x-layouts::app>
