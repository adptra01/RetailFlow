<?php

use App\Models\Supplier;
use Flux\Flux;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\state;

name('suppliers.create');
middleware('auth');
middleware('verified');

state([
    'name' => '',
    'contact_person' => '',
    'phone' => '',
    'email' => '',
    'address' => '',
    'description' => '',
]);

$save = function () {
    $validated = $this->validate([
        'name' => 'required|string|max:200|unique:suppliers,name',
        'contact_person' => 'nullable|string|max:200',
        'phone' => 'nullable|string|max:50',
        'email' => 'nullable|email|max:200',
        'address' => 'nullable|string',
        'description' => 'nullable|string',
    ]);

    Supplier::create($validated);

    Flux::toast(variant: 'success', text: __('Supplier created.'));

    $this->redirectRoute('suppliers.index');
};

?>

<x-layouts::app :title="__('Create Supplier')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item href="/suppliers">{{ __('Suppliers') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Create') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div>
                <flux:heading size="xl">{{ __('Create Supplier') }}</flux:heading>
                <flux:subheading>{{ __('Add a new supplier.') }}</flux:subheading>
            </div>

            <form wire:submit="save">
                <div class="max-w-2xl space-y-6">
                    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="space-y-5">
                            <flux:input wire:model="name" :label="__('Supplier Name')" placeholder="{{ __('e.g. PT Indofood') }}" required autofocus />
                            <div class="grid grid-cols-2 gap-4">
                                <flux:input wire:model="contact_person" :label="__('Contact Person')" placeholder="{{ __('Nama sales') }}" />
                                <flux:input wire:model="phone" :label="__('Phone')" placeholder="{{ __('Nomor telepon') }}" />
                            </div>
                            <flux:input wire:model="email" :label="__('Email')" type="email" placeholder="{{ __('email@supplier.com') }}" />
                            <flux:textarea wire:model="address" :label="__('Address')" placeholder="{{ __('Alamat supplier...') }}" />
                            <flux:textarea wire:model="description" :label="__('Notes')" placeholder="{{ __('Catatan tambahan...') }}" />
                        </div>
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:button variant="filled" href="/suppliers">{{ __('Cancel') }}</flux:button>
                        <flux:button variant="primary" type="submit">{{ __('Save Supplier') }}</flux:button>
                    </div>
                </div>
            </form>
        </div>
    @endvolt
</x-layouts::app>
