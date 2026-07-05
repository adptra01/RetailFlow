<?php

use App\Models\Supplier;
use Flux\Flux;
use Livewire\WithPagination;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

name('suppliers.index');
middleware('auth');
middleware('verified');
uses(WithPagination::class);

state(['search' => ''])->url();

$suppliers = computed(function () {
    return Supplier::query()
        ->withCount('products', 'purchaseOrders')
        ->where(function ($q) {
            if ($this->search) {
                $q->where('name', 'like', '%' . $this->search . '%')
                  ->orWhere('contact_person', 'like', '%' . $this->search . '%');
            }
        })
        ->orderBy('name')
        ->paginate(10);
});

$confirmDelete = function (Supplier $supplier) {
    if ($supplier->products()->count() > 0 || $supplier->purchaseOrders()->count() > 0) {
        Flux::toast(variant: 'error', text: __('Cannot delete supplier with associated data.'));
        return;
    }
    $supplier->delete();
    Flux::toast(variant: 'success', text: __('Supplier deleted.'));
};

?>

<x-layouts::app :title="__('Suppliers')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Suppliers') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="xl">{{ __('Suppliers') }}</flux:heading>
                    <flux:subheading>{{ __('Manage your suppliers') }}</flux:subheading>
                </div>
                <flux:button variant="primary" icon="plus" href="/suppliers/create">
                    {{ __('Add Supplier') }}
                </flux:button>
            </div>

            <flux:input size="md" wire:model.live="search" type="search"
                placeholder="{{ __('Search suppliers...') }}" />

            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <flux:table :paginate="$this->suppliers">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Name') }}</flux:table.column>
                        <flux:table.column>{{ __('Contact') }}</flux:table.column>
                        <flux:table.column>{{ __('Phone') }}</flux:table.column>
                        <flux:table.column>{{ __('Products') }}</flux:table.column>
                        <flux:table.column>{{ __('PO') }}</flux:table.column>
                        <flux:table.column>{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->suppliers as $supplier)
                            <flux:table.row :key="$supplier->id">
                                <flux:table.cell class="font-medium">{{ $supplier->name }}</flux:table.cell>
                                <flux:table.cell class="text-zinc-500">{{ $supplier->contact_person ?: '-' }}</flux:table.cell>
                                <flux:table.cell>{{ $supplier->phone ?: '-' }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" inset="top bottom">{{ $supplier->products_count }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" inset="top bottom">{{ $supplier->purchase_orders_count }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" />
                                        <flux:menu>
                                            <flux:menu.item icon="pencil" href="/suppliers/{{ $supplier->id }}">{{ __('Edit') }}</flux:menu.item>
                                            <flux:menu.separator />
                                            <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $supplier->id }})">{{ __('Delete') }}</flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>
    @endvolt
</x-layouts::app>
