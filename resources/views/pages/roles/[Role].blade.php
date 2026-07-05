<?php

use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Flux\Flux;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

name('roles.edit');
middleware('auth');
middleware('verified');

state([
    'role' => null,
    'name' => '',
    'selectedPermissions' => [],
]);

mount(function (Role $role) {
    $this->role = $role;
    $this->name = $role->name;
    $this->selectedPermissions = $role->permissions->pluck('name')->toArray();
});

$permissions = computed(fn() => Permission::all());

$save = function () {
    $this->validate([
        'name' => 'required|string|max:255|unique:roles,name,' . $this->role->id,
    ]);

    $this->role->update(['name' => $this->name]);
    $this->role->syncPermissions($this->selectedPermissions);

    Flux::toast(variant: 'success', text: __('Role updated.'));

    $this->redirect('/roles', wire: true);
};

?>

<x-layouts::app :title="__('Edit Role')">
    @volt
    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item href="/dashboard">{{ __('Home') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item href="/roles">{{ __('Roles') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ __('Edit Role') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl">{{ __('Edit Role') }}</flux:heading>
                <flux:subheading>{{ __('Define role name and assign permissions.') }}</flux:subheading>
            </div>
        </div>

        <div class="w-full rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <form wire:submit="save" class="space-y-6">
                <flux:input wire:model="name" :label="__('Name')" required />

                <div>
                    <flux:label>{{ __('Permissions') }}</flux:label>
                    <div class="mt-2 grid grid-cols-2 gap-2 md:grid-cols-3">
                        @foreach($this->permissions as $permission)
                            <flux:checkbox wire:model="selectedPermissions" :value="$permission->name"
                                :label="$permission->name" />
                        @endforeach
                    </div>
                </div>

                <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <flux:button variant="filled" href="/roles">{{ __('Cancel') }}</flux:button>
                    <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </div>
    </div>
    @endvolt
</x-layouts::app>