<?php

use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;

name('users.create');
middleware('auth');
middleware('verified');

state([
    'name' => '',
    'email' => '',
    'password' => '',
    'password_confirmation' => '',
    'selectedRoles' => [],
]);

$roles = computed(fn() => Role::whereNot('name', 'admin')->get());

$save = function () {
    $this->validate([
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
        'password' => ['required', 'string', 'min:8', 'confirmed'],
    ]);

    $user = User::create([
        'name' => $this->name,
        'email' => $this->email,
        'password' => Hash::make($this->password),
    ]);

    $user->syncRoles($this->selectedRoles);

    Flux::toast(variant: 'success', text: __('User created.'));

    $this->redirect('/users', wire: true);
};

?>

<x-layouts::app :title="__('Add User')">
    @volt
    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item href="/dashboard">{{ __('Home') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item href="/users">{{ __('Users') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ __('Add User') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl">{{ __('Add User') }}</flux:heading>
                <flux:subheading>{{ __('Create a new user account.') }}</flux:subheading>
            </div>
        </div>

        <div class="w-full rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <form wire:submit="save" class="space-y-6">
                <flux:input wire:model="name" :label="__('Name')" required />
                <flux:input wire:model="email" type="email" :label="__('Email')" required />

                <div class="grid grid-cols-2 gap-4">
                    <flux:input wire:model="password" type="password" :label="__('Password')" required viewable />
                    <flux:input wire:model="password_confirmation" type="password" :label="__('Confirm Password')"
                        required viewable />
                </div>

                <div>
                    <flux:label>{{ __('Roles') }}</flux:label>
                    <div class="mt-2 flex flex-wrap gap-4">
                        @foreach($this->roles as $role)
                            <flux:checkbox wire:model="selectedRoles" :value="$role->name" :label="$role->name" />
                        @endforeach
                    </div>
                </div>

                <div class="flex justify-end gap-2 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                    <flux:button variant="filled" href="/users">{{ __('Cancel') }}</flux:button>
                    <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </div>
    </div>
    @endvolt
</x-layouts::app>