<?php

use App\Models\Customer;
use Flux\Flux;
use Livewire\WithPagination;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

name('customers.index');
middleware('auth');
middleware('verified');
uses(WithPagination::class);

state(['search' => ''])->url();

$customers = computed(function () {
    return Customer::query()
        ->where(function ($q) {
            if ($this->search) {
                $q->where('name', 'like', '%' . $this->search . '%')
                  ->orWhere('phone', 'like', '%' . $this->search . '%');
            }
        })
        ->orderBy('name')
        ->paginate(10);
});

$confirmDelete = function (Customer $customer) {
    $customer->delete();
    Flux::toast(variant: 'success', text: __('Customer deleted.'));
};

?>

<x-layouts::app :title="__('Customers')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Customers') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="xl">{{ __('Customers') }}</flux:heading>
                    <flux:subheading>{{ __('Manage your customers') }}</flux:subheading>
                </div>
                <flux:button variant="primary" icon="plus" href="/customers/create">
                    {{ __('Add Customer') }}
                </flux:button>
            </div>

            <flux:input size="md" wire:model.live="search" type="search"
                placeholder="{{ __('Search customers...') }}" />

            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <flux:table :paginate="$this->customers">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Name') }}</flux:table.column>
                        <flux:table.column>{{ __('Phone') }}</flux:table.column>
                        <flux:table.column>{{ __('Credit Limit') }}</flux:table.column>
                        <flux:table.column>{{ __('Balance') }}</flux:table.column>
                        <flux:table.column>{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->customers as $customer)
                            <flux:table.row :key="$customer->id">
                                <flux:table.cell class="font-medium">{{ $customer->name }}</flux:table.cell>
                                <flux:table.cell>{{ $customer->phone ?: '-' }}</flux:table.cell>
                                <flux:table.cell>{{ Number::currency($customer->credit_limit, 'IDR', 'id') }}</flux:table.cell>
                                <flux:table.cell>
                                    <span @class(['font-medium', 'text-rose-600' => $customer->balance > 0])>
                                        {{ Number::currency($customer->balance, 'IDR', 'id') }}
                                    </span>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" />
                                        <flux:menu>
                                            <flux:menu.item icon="pencil" href="/customers/{{ $customer->id }}">{{ __('Edit') }}</flux:menu.item>
                                            <flux:menu.separator />
                                            <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $customer->id }})">{{ __('Delete') }}</flux:menu.item>
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
