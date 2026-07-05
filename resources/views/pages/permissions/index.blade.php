<?php

use Spatie\Permission\Models\Permission;
use Flux\Flux;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;

name('permissions.index');
middleware('auth');
middleware('verified');

state([
    'search' => '',
]);

$permissions = computed(fn() => Permission::where('name', 'like', '%' . $this->search . '%')->get());

$deletePermission = function ($id) {
    Permission::findById($id)->delete();
    Flux::toast(variant: 'success', text: __('Permission deleted.'));
};

?>

<x-layouts::app :title="__('Permissions')">
    @volt
    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ __('Permissions') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl">{{ __('Permissions') }}</flux:heading>
                <flux:subheading>{{ __('Manage Permissions') }}</flux:subheading>
            </div>
            @can('permissions.create')
            <flux:button variant="primary" icon="plus" href="/permissions/create">
                {{ __('Add Permission') }}
            </flux:button>
            @endcan
        </div>

        {{-- Search --}}
        <flux:input size="md" wire:model.live="search" type="search" placeholder="{{ __('Search') }}..." />

        {{-- Table Container --}}
        <div
            class="relative h-full flex-1 overflow-hidden rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->permissions as $permission)
                        <flux:table.row :key="$permission->id">
                            <flux:table.cell class="font-medium text-zinc-900 dark:text-zinc-100">
                                {{ $permission->name }}
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" />
                                    <flux:menu>
                                        @can('permissions.edit')
                                        <flux:menu.item icon="pencil" href="/permissions/{{ $permission->id }}">
                                            {{ __('Edit') }}
                                        </flux:menu.item>
                                        @endcan
                                        @can('permissions.delete')
                                        <flux:menu.separator />
                                        <flux:menu.item icon="trash" variant="danger"
                                            wire:confirm="{{ __('Are you sure?') }}"
                                            wire:click="deletePermission({{ $permission->id }})">
                                            {{ __('Delete') }}
                                        </flux:menu.item>
                                        @endcan
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