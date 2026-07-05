<?php

use App\Models\Brand;
use Flux\Flux;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\state;

name('brands.create');
middleware('auth');
middleware('verified');

state([
    'name' => '',
    'description' => '',
]);

$save = function () {
    $validated = $this->validate([
        'name' => 'required|string|max:200|unique:brands,name',
        'description' => 'nullable|string',
    ]);

    Brand::create($validated);

    Flux::toast(variant: 'success', text: __('Brand created.'));

    $this->redirectRoute('brands.index');
};

?>

<x-layouts::app :title="__('Create Brand')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item href="/brands">{{ __('Brands') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Create') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div>
                <flux:heading size="xl">{{ __('Create Brand') }}</flux:heading>
                <flux:subheading>{{ __('Add a new product brand.') }}</flux:subheading>
            </div>

            <form wire:submit="save">
                <div class="max-w-2xl space-y-6">
                    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                        <flux:input wire:model="name" :label="__('Brand Name')" placeholder="{{ __('e.g. Indofood') }}" required autofocus />
                        <div class="mt-5">
                            <flux:textarea wire:model="description" :label="__('Description')" placeholder="{{ __('Optional description...') }}" />
                        </div>
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:button variant="filled" href="/brands">{{ __('Cancel') }}</flux:button>
                        <flux:button variant="primary" type="submit">{{ __('Save Brand') }}</flux:button>
                    </div>
                </div>
            </form>
        </div>
    @endvolt
</x-layouts::app>
