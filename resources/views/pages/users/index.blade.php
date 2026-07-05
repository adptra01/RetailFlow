<?php

use App\Models\User;
use Flux\Flux;
use Livewire\WithPagination;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

name('users.index');
middleware('auth');
middleware('verified');

uses(WithPagination::class);

state([
    'search' => '',
    'sortBy' => 'name',
    'sortDirection' => 'asc',
]);

$users = computed(function () {
    return User::query()
        ->whereNot('email', 'admin@testing.com')
        ->orWhereNot('name', 'Admin POS')
        ->where(function ($query) {
            $query->where('name', 'like', '%' . $this->search . '%')
                ->orWhere('email', 'like', '%' . $this->search . '%');
        })
        ->orderBy($this->sortBy, $this->sortDirection)
        ->paginate(10);
});

$sort = function ($column) {
    if ($this->sortBy === $column) {
        $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
    } else {
        $this->sortBy = $column;
        $this->sortDirection = 'asc';
    }
};

$deleteUser = function ($id) {
    User::find($id)->delete();
    Flux::toast(variant: 'success', text: __('User deleted.'));
};

?>

<x-layouts::app :title="__('Users')">
    @volt
    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ __('Users') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl">{{ __('Users') }}</flux:heading>
                <flux:subheading>{{ __('Manage Users') }}</flux:subheading>
            </div>
            @can('users.create')
            <flux:button variant="primary" icon="plus" href="/users/create">
                {{ __('Add User') }}
            </flux:button>
            @endcan
        </div>

        {{-- Search --}}
        <flux:input size="md" wire:model.live="search" type="search" placeholder="{{ __('Search') }}..." />

        {{-- Table Container --}}
        <div
            class="relative h-full flex-1 overflow-hidden rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
            <flux:table :paginate="$this->users">
                <flux:table.columns>
                    <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection"
                        wire:click="sort('name')">{{ __('Name') }}</flux:table.column>
                    <flux:table.column sortable :sorted="$sortBy === 'email'" :direction="$sortDirection"
                        wire:click="sort('email')">{{ __('Email') }}</flux:table.column>
                    <flux:table.column>{{ __('Roles') }}</flux:table.column>
                    <flux:table.column>{{ __('Actions') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->users as $user)
                        <flux:table.row :key="$user->id">
                            <flux:table.cell class="font-medium text-zinc-900 dark:text-zinc-100">{{ $user->name }}
                            </flux:table.cell>
                            <flux:table.cell>{{ $user->email }}</flux:table.cell>
                            <flux:table.cell>
                                <div class="flex flex-wrap gap-1">
                                    @foreach($user->roles as $role)
                                        <flux:badge size="sm" inset="top bottom">{{ $role->name }}</flux:badge>
                                    @endforeach
                                </div>
                            </flux:table.cell>
                            <flux:table.cell>
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" />
                                    <flux:menu>
                                        @can('users.edit')
                                        <flux:menu.item icon="pencil" href="/users/{{ $user->id }}">
                                            {{ __('Edit') }}
                                        </flux:menu.item>
                                        @endcan
                                        @can('users.delete')
                                        <flux:menu.separator />
                                        <flux:menu.item icon="trash" variant="danger"
                                            wire:confirm="{{ __('Are you sure?') }}"
                                            wire:click="deleteUser({{ $user->id }})">
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