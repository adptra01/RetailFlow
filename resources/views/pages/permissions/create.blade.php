<?php

use Spatie\Permission\Models\Permission;
use Flux\Flux;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\state;

name('permissions.create');
middleware('auth');
middleware('verified');

state([
    'name' => '',
]);

$save = function () {
    $this->validate([
        'name' => 'required|string|max:255|unique:permissions,name',
    ]);

    Permission::create(['name' => $this->name]);

    Flux::toast(variant: 'success', text: __('Permission created.'));

    $this->redirect('/permissions', wire: true);
};

?>

<x-layouts::app :title="__('Add Permission')">
    @volt
    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item href="/dashboard">{{ __('Home') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item href="/permissions">{{ __('Permissions') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ __('Add Permission') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl">{{ __('Add Permission') }}</flux:heading>
                <flux:subheading>{{ __('Define permission name (e.g., manage-users).') }}</flux:subheading>
            </div>
        </div>

        <div class="w-full rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <form wire:submit="save" class="space-y-6">
                <flux:input wire:model="name" :label="__('Name')" placeholder="kelola-pengguna" required />

                <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <flux:button variant="filled" href="/permissions">{{ __('Cancel') }}</flux:button>
                    <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </div>
    </div>
    @endvolt
</x-layouts::app>