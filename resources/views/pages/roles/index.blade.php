<?php

use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Flux\Flux;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;

name('roles.index');
middleware('auth');
middleware('verified');

state([
    'search' => '',
]);

$roles = computed(
    fn() => Role::with('permissions')
        ->where('name', 'like', '%' . $this->search . '%')
        ->get()
);

$deleteRole = function ($id) {
    Role::findById($id)->delete();
    Flux::toast(variant: 'success', text: __('Role deleted.'));
};

?>

<x-layouts::app :title="__('Roles')">
    @volt
    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ __('Roles') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl">{{ __('Roles') }}</flux:heading>
                <flux:subheading>{{ __('Manage Roles') }}</flux:subheading>
            </div>
            @can('roles.create')
            <flux:button variant="primary" icon="plus" href="/roles/create">
                {{ __('Add Role') }}
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
                    <flux:table.column>{{ __('Permissions') }}</flux:table.column>
                    <flux:table.column>{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->roles as $role)
                        <flux:table.row :key="$role->id">
                            <flux:table.cell class="font-medium text-zinc-900 dark:text-zinc-100">
                                {{ $role->name }}
                            </flux:table.cell>
                            <flux:table.cell>
                                <div class="flex flex-wrap gap-1">
                                    @forelse ($role->permissions as $permission)
                                        <flux:badge size="sm" inset="top bottom">{{ $permission->name }}</flux:badge>
                                    @empty
                                        <span class="text-xs text-zinc-500 italic">{{ __('No permissions') }}</span>
                                    @endforelse
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" />
                                    <flux:menu>
                                        @can('roles.edit')
                                        <flux:menu.item icon="pencil" href="/roles/{{ $role->id }}">
                                            {{ __('Edit') }}
                                        </flux:menu.item>
                                        @endcan
                                        @can('roles.delete')
                                        <flux:menu.separator />
                                        <flux:menu.item icon="trash" variant="danger"
                                            wire:confirm="{{ __('Are you sure?') }}"
                                            wire:click="deleteRole({{ $role->id }})">
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