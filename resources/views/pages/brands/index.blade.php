<?php

use App\Models\Brand;
use Flux\Flux;
use Livewire\WithPagination;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

name('brands.index');
middleware('auth');
middleware('verified');
uses(WithPagination::class);

state(['search' => ''])->url();

$brands = computed(function () {
    return Brand::query()
        ->withCount('products')
        ->where(function ($q) {
            if ($this->search) {
                $q->where('name', 'like', '%' . $this->search . '%');
            }
        })
        ->orderBy('name')
        ->paginate(10);
});

$confirmDelete = function (Brand $brand) {
    if ($brand->products()->count() > 0) {
        Flux::toast(variant: 'error', text: __('Cannot delete brand with associated products.'));
        return;
    }
    $brand->delete();
    Flux::toast(variant: 'success', text: __('Brand deleted.'));
};

?>

<x-layouts::app :title="__('Brands')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Brands') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="xl">{{ __('Brands') }}</flux:heading>
                    <flux:subheading>{{ __('Manage product brands') }}</flux:subheading>
                </div>
                <flux:button variant="primary" icon="plus" href="/brands/create">
                    {{ __('Add Brand') }}
                </flux:button>
            </div>

            <flux:input size="md" wire:model.live="search" type="search"
                placeholder="{{ __('Search brands...') }}" />

            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <flux:table :paginate="$this->brands">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Name') }}</flux:table.column>
                        <flux:table.column>{{ __('Description') }}</flux:table.column>
                        <flux:table.column>{{ __('Products') }}</flux:table.column>
                        <flux:table.column>{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->brands as $brand)
                            <flux:table.row :key="$brand->id">
                                <flux:table.cell class="font-medium">{{ $brand->name }}</flux:table.cell>
                                <flux:table.cell class="text-zinc-500">{{ Str::limit($brand->description, 60) ?: '-' }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" inset="top bottom">{{ $brand->products_count }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" />
                                        <flux:menu>
                                            <flux:menu.item icon="pencil" href="/brands/{{ $brand->id }}">
                                                {{ __('Edit') }}
                                            </flux:menu.item>
                                            <flux:menu.separator />
                                            <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $brand->id }})">
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
        </div>
    @endvolt
</x-layouts::app>
