<?php

use App\Models\Customer;
use Flux\Flux;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\state;

name('customers.create');
middleware('auth');
middleware('verified');

state([
    'name' => '',
    'phone' => '',
    'address' => '',
    'credit_limit' => 0,
    'notes' => '',
]);

$save = function () {
    $validated = $this->validate([
        'name' => 'required|string|max:200',
        'phone' => 'nullable|string|max:50',
        'address' => 'nullable|string',
        'credit_limit' => 'nullable|numeric|min:0',
        'notes' => 'nullable|string',
    ]);

    Customer::create($validated);

    Flux::toast(variant: 'success', text: __('Customer created.'));

    $this->redirectRoute('customers.index');
};

?>

<x-layouts::app :title="__('Create Customer')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item href="/customers">{{ __('Customers') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Create') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div>
                <flux:heading size="xl">{{ __('Create Customer') }}</flux:heading>
                <flux:subheading>{{ __('Add a new customer.') }}</flux:subheading>
            </div>

            <form wire:submit="save">
                <div class="max-w-2xl space-y-6">
                    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="space-y-5">
                            <flux:input wire:model="name" :label="__('Customer Name')" placeholder="{{ __('Nama pelanggan') }}" required autofocus />
                            <div class="grid grid-cols-2 gap-4">
                                <flux:input wire:model="phone" :label="__('Phone')" placeholder="{{ __('Nomor telepon') }}" />
                                <flux:input wire:model="credit_limit" :label="__('Credit Limit')" type="number" step="0.01" min="0" placeholder="0" prefix="Rp" />
                            </div>
                            <flux:textarea wire:model="address" :label="__('Address')" placeholder="{{ __('Alamat...') }}" />
                            <flux:textarea wire:model="notes" :label="__('Notes')" placeholder="{{ __('Catatan...') }}" />
                        </div>
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:button variant="filled" href="/customers">{{ __('Cancel') }}</flux:button>
                        <flux:button variant="primary" type="submit">{{ __('Save Customer') }}</flux:button>
                    </div>
                </div>
            </form>
        </div>
    @endvolt
</x-layouts::app>
