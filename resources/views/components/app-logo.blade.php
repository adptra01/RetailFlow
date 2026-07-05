@props([
    'sidebar' => false,
    'store_name' => optional(\App\Models\Setting::first())->store_name ?? config('app.name'),
])

@if($sidebar)
    <flux:sidebar.brand name="{{ $store_name }}" {{ $attributes }}>
        <x-slot name="logo" class="hidden aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
            <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="{{ $store_name }}" {{ $attributes }}>
        <x-slot name="logo" class="hidden aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
            <x-app-logo-icon class="size-5 fill-current text-white dark:text-black" />
        </x-slot>
    </flux:brand>
@endif
