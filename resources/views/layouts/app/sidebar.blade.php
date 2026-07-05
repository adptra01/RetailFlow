<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">

<head>
    @include('partials.head')
</head>

<body class="min-h-screen bg-white dark:bg-zinc-800">
    <flux:sidebar sticky collapsible="mobile"
        class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.header>
            <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" />
            <flux:sidebar.collapse class="lg:hidden" />
        </flux:sidebar.header>

        <flux:sidebar.nav>
            <flux:sidebar.group :heading="__('Analitik & Transaksi')" class="grid">
                <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')">
                    {{ __('Dashboard') }}
                </flux:sidebar.item>

                @can('transactions.view')
                <flux:sidebar.item icon="document-text" href="{{ route('transactions.index') }}">
                    {{ __('Transactions') }}
                </flux:sidebar.item>
                @endcan

                @can('reports.view')
                <flux:sidebar.item icon="chart-bar-square" href="{{ route('reports.index') }}">
                    {{ __('Laporan') }}
                </flux:sidebar.item>
                @endcan
            </flux:sidebar.group>

            @can('products.view')
            <flux:sidebar.group :heading="__('Manajemen Produk')" class="grid mt-4">
                <flux:sidebar.item icon="shopping-bag" href="{{ route('products.index') }}">
                    {{ __('Products') }}
                </flux:sidebar.item>

                @can('categories.view')
                <flux:sidebar.item icon="folder-git-2" href="{{ route('categories.index') }}">
                    {{ __('Kategori Produk') }}
                </flux:sidebar.item>
                @endcan
            </flux:sidebar.group>
            @endcan

            @can('users.view')
            <flux:sidebar.group :heading="__('Keamanan & Akses')" class="grid mt-4">
                <flux:sidebar.item icon="users" href="/users">
                    {{ __('Users') }}
                </flux:sidebar.item>

                @can('roles.view')
                <flux:sidebar.item icon="shield-check" href="/roles">
                    {{ __('Roles') }}
                </flux:sidebar.item>
                @endcan

                @can('permissions.view')
                <flux:sidebar.item icon="key" href="/permissions">
                    {{ __('Permissions') }}
                </flux:sidebar.item>
                @endcan
            </flux:sidebar.group>
            @endcan

            @can('settings.store')
            <flux:sidebar.group :heading="__('Konfigurasi')" class="grid mt-4">
                <flux:sidebar.item icon="cog-6-tooth" href="/settings/store">
                    {{ __('Store Settings') }}
                </flux:sidebar.item>
            </flux:sidebar.group>
            @endcan
        </flux:sidebar.nav>

        <flux:spacer />

        {{-- <flux:sidebar.nav>
            <flux:sidebar.item icon="folder-git-2" href="https://github.com/laravel/livewire-starter-kit"
                target="_blank">
                {{ __('Repository') }}
            </flux:sidebar.item>

            <flux:sidebar.item icon="book-open-text" href="https://laravel.com/docs/starter-kits#livewire"
                target="_blank">
                {{ __('Documentation') }}
            </flux:sidebar.item>
        </flux:sidebar.nav> --}}

        <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
    </flux:sidebar>

    <!-- Mobile User Menu -->
    <flux:header class="lg:hidden">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        <flux:spacer />

        <flux:dropdown position="top" align="end">
            <flux:profile :initials="auth()->user()->initials()" icon-trailing="chevron-down" />

            <flux:menu>
                <flux:menu.radio.group>
                    <div class="p-0 text-sm font-normal">
                        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                            <flux:avatar :name="auth()->user()->name" :initials="auth()->user()->initials()" />

                            <div class="grid flex-1 text-start text-sm leading-tight">
                                <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                            </div>
                        </div>
                    </div>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <flux:menu.radio.group>
                    <flux:menu.item :href="route('profile.edit')" icon="cog">
                        {{ __('Settings') }}
                    </flux:menu.item>
                </flux:menu.radio.group>

                <flux:menu.separator />

                <form method="POST" action="{{ route('logout') }}" class="w-full">
                    @csrf
                    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle"
                        class="w-full cursor-pointer" data-test="logout-button">
                        {{ __('Log out') }}
                    </flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>
    </flux:header>

    {{ $slot }}

    @persist('toast')
    <flux:toast.group position="top end">
        <flux:toast />
    </flux:toast.group>
    @endpersist

    @fluxScripts
    @stack('scripts')
</body>

</html>