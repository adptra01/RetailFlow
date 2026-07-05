<?php

use App\Models\Supplier;
use Flux\Flux;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

name('suppliers.edit');
middleware('auth');
middleware('verified');

state(['name' => '', 'contact_person' => '', 'phone' => '', 'email' => '', 'address' => '', 'description' => '']);

mount(function () {
    $supplier = Supplier::findOrFail(request()->route('supplier'));
    $this->name = $supplier->name;
    $this->contact_person = $supplier->contact_person;
    $this->phone = $supplier->phone;
    $this->email = $supplier->email;
    $this->address = $supplier->address;
    $this->description = $supplier->description;
});

$supplier = computed(function () {
    return Supplier::findOrFail(request()->route('supplier'));
});

$save = function () {
    $validated = $this->validate([
        'name' => 'required|string|max:200|unique:suppliers,name,' . $this->supplier->id,
        'contact_person' => 'nullable|string|max:200',
        'phone' => 'nullable|string|max:50',
        'email' => 'nullable|email|max:200',
        'address' => 'nullable|string',
        'description' => 'nullable|string',
    ]);

    $this->supplier->update($validated);

    Flux::toast(variant: 'success', text: __('Supplier updated.'));

    $this->redirectRoute('suppliers.index');
};

?>

<x-layouts::app :title="__('Edit Supplier')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item href="/suppliers">{{ __('Suppliers') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Edit') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div>
                <flux:heading size="xl">{{ $this->supplier->name }}</flux:heading>
                <flux:subheading>{{ __('Edit supplier information.') }}</flux:subheading>
            </div>

            <form wire:submit="save">
                <div class="max-w-2xl space-y-6">
                    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="space-y-5">
                            <flux:input wire:model="name" :label="__('Supplier Name')" required autofocus />
                            <div class="grid grid-cols-2 gap-4">
                                <flux:input wire:model="contact_person" :label="__('Contact Person')" />
                                <flux:input wire:model="phone" :label="__('Phone')" />
                            </div>
                            <flux:input wire:model="email" :label="__('Email')" type="email" />
                            <flux:textarea wire:model="address" :label="__('Address')" />
                            <flux:textarea wire:model="description" :label="__('Notes')" />
                        </div>
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:button variant="filled" href="/suppliers">{{ __('Cancel') }}</flux:button>
                        <flux:button variant="primary" type="submit">{{ __('Update Supplier') }}</flux:button>
                    </div>
                </div>
            </form>
        </div>
    @endvolt
</x-layouts::app>
