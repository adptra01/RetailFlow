<?php

use App\Models\Brand;
use Flux\Flux;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

name('brands.edit');
middleware('auth');
middleware('verified');

state(['name' => '', 'description' => '']);

mount(function () {
    $brand = Brand::findOrFail(request()->route('brand'));
    $this->name = $brand->name;
    $this->description = $brand->description;
});

$brand = computed(function () {
    return Brand::findOrFail(request()->route('brand'));
});

$save = function () {
    $validated = $this->validate([
        'name' => 'required|string|max:200|unique:brands,name,' . $this->brand->id,
        'description' => 'nullable|string',
    ]);

    $this->brand->update($validated);

    Flux::toast(variant: 'success', text: __('Brand updated.'));

    $this->redirectRoute('brands.index');
};

?>

<x-layouts::app :title="__('Edit Brand')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item href="/brands">{{ __('Brands') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Edit') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div>
                <flux:heading size="xl">{{ $this->brand->name }}</flux:heading>
                <flux:subheading>{{ __('Edit brand information.') }}</flux:subheading>
            </div>

            <form wire:submit="save">
                <div class="max-w-2xl space-y-6">
                    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                        <flux:input wire:model="name" :label="__('Brand Name')" required autofocus />
                        <div class="mt-5">
                            <flux:textarea wire:model="description" :label="__('Description')" placeholder="{{ __('Optional description...') }}" />
                        </div>
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:button variant="filled" href="/brands">{{ __('Cancel') }}</flux:button>
                        <flux:button variant="primary" type="submit">{{ __('Update Brand') }}</flux:button>
                    </div>
                </div>
            </form>
        </div>
    @endvolt
</x-layouts::app>
