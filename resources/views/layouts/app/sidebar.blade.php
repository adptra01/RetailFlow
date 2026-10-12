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
            {{-- Dashboard --}}
            <flux:sidebar.group :heading="__('Ringkasan')" class="grid">
                <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')">
                    {{ __('Dashboard') }}
                </flux:sidebar.item>

                @php($unreadAlerts = auth()->user()?->tenant_id === null ? 0 : \App\Models\Alert::query()->where('tenant_id', auth()->user()->tenant_id)->whereNull('read_at')->count())
                <flux:sidebar.item icon="bell" :href="route('alerts.index')"
                    :current="request()->routeIs('alerts.*')">
                    {{ __('Notifikasi') }}
                    @if ($unreadAlerts > 0)
                        <flux:badge color="rose" size="sm" class="ms-auto">{{ $unreadAlerts }}</flux:badge>
                    @endif
                </flux:sidebar.item>
            </flux:sidebar.group>

            {{-- Penjualan --}}
            @php($tenant = auth()->user()->tenant)
            @php($featureManager = $tenant ? App\Support\Features\FeatureProfileManager::for($tenant) : null)
            @if ($featureManager && ($featureManager->isEnabled(App\Enums\Module::Cashier) || $featureManager->isEnabled(App\Enums\Module::History) || $featureManager->isEnabled(App\Enums\Module::Customers) || $featureManager->isEnabled(App\Enums\Module::Receivables) || $featureManager->isEnabled(App\Enums\Module::Promotions)))
            <flux:sidebar.group :heading="__('Penjualan')" class="grid mt-4">
                @if ($featureManager->isEnabled(App\Enums\Module::Cashier))
                @can('transactions.create')
                <flux:sidebar.item icon="plus-circle" href="{{ route('transactions.create') }}"
                    :current="request()->routeIs('transactions.create')">
                    {{ __('POS') }}
                </flux:sidebar.item>
                @endcan
                @endif

                @if ($featureManager->isEnabled(App\Enums\Module::History))
                @can('transactions.view')
                <flux:sidebar.item icon="document-text" href="{{ route('transactions.index') }}"
                    :current="request()->routeIs('transactions.index')">
                    {{ __('Transaksi') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="arrow-uturn-left" href="/returns/sales"
                    :current="request()->routeIs('returns.sales.*')">
                    {{ __('Retur Penjualan') }}
                </flux:sidebar.item>
                @endcan
                @endif

                @if ($featureManager->isEnabled(App\Enums\Module::Receivables))
                @can('customers.view') {{-- Piutang terkait Customer --}}
                <flux:sidebar.item icon="credit-card" href="/payments/customer"
                    :current="request()->routeIs('payments.customer.*')">
                    {{ __('Piutang Customer') }}
                </flux:sidebar.item>
                @endcan
                @endif

                @if ($featureManager->isEnabled(App\Enums\Module::Promotions))
                @can('promotions.view')
                <flux:sidebar.item icon="tag" href="/promotions"
                    :current="request()->routeIs('promotions.*')">
                    {{ __('Promosi') }}
                </flux:sidebar.item>
                @endcan
                @endif
            </flux:sidebar.group>
            @endif

            {{-- F&B (Makanan & Minuman) --}}
            @if ($featureManager && ($featureManager->isEnabled(App\Enums\Module::Menu) || $featureManager->isEnabled(App\Enums\Module::Orders) || $featureManager->isEnabled(App\Enums\Module::Ingredients)))
            <flux:sidebar.group :heading="__('Makanan & Minuman')" class="grid mt-4">
                @if ($featureManager->isEnabled(App\Enums\Module::Menu))
                @can('products.view')
                <flux:sidebar.item icon="list-bullet" href="/menu"
                    :current="request()->routeIs('menu.*')">
                    {{ __('Menu') }}
                </flux:sidebar.item>
                @endcan
                @endif

                @if ($featureManager->isEnabled(App\Enums\Module::Orders))
                @can('transactions.view')
                <flux:sidebar.item icon="clipboard-document-list" href="/orders"
                    :current="request()->routeIs('orders.*')">
                    {{ __('Pesanan') }}
                </flux:sidebar.item>
                @endcan
                @endif

                @if ($featureManager->isEnabled(App\Enums\Module::Ingredients))
                @can('ingredients.view')
                <flux:sidebar.item icon="beaker" href="/ingredients"
                    :current="request()->routeIs('ingredients.*')">
                    {{ __('Bahan Baku') }}
                </flux:sidebar.item>
                @endcan
                @endif
            </flux:sidebar.group>
            @endif

            {{-- Pembelian --}}
            @if ($featureManager && ($featureManager->isEnabled(App\Enums\Module::Purchases) || $featureManager->isEnabled(App\Enums\Module::Suppliers)))
            <flux:sidebar.group :heading="__('Pembelian')" class="grid mt-4">
                @if ($featureManager->isEnabled(App\Enums\Module::Purchases))
                @can('purchases.view')
                <flux:sidebar.item icon="truck" href="/purchases"
                    :current="request()->routeIs('purchases.*')">
                    {{ __('Purchase Order') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="arrow-uturn-left" href="/returns/purchase"
                    :current="request()->routeIs('returns.purchase.*')">
                    {{ __('Retur Pembelian') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="banknotes" href="/payments/supplier"
                    :current="request()->routeIs('payments.supplier.*')">
                    {{ __('Hutang Supplier') }}
                </flux:sidebar.item>
                @endcan
                @endif
            </flux:sidebar.group>
            @endif

            {{-- Master Data --}}
            @if ($featureManager && ($featureManager->isEnabled(App\Enums\Module::Products) || $featureManager->isEnabled(App\Enums\Module::Suppliers) || $featureManager->isEnabled(App\Enums\Module::Customers)))
            <flux:sidebar.group :heading="__('Master Data')" class="grid mt-4">
                @if ($featureManager->isEnabled(App\Enums\Module::Products))
                @can('products.view')
                <flux:sidebar.item icon="shopping-bag" href="{{ route('products.index') }}"
                    :current="request()->routeIs('products.*')">
                    {{ __('Produk') }}
                </flux:sidebar.item>
                @endcan

                @can('products.create')
                <flux:sidebar.item icon="arrow-up-tray" href="/imports/products"
                    :current="request()->routeIs('imports.products')">
                    {{ __('Impor Produk') }}
                </flux:sidebar.item>
                @endcan

                @can('categories.view')
                <flux:sidebar.item icon="folder-git-2" href="{{ route('categories.index') }}"
                    :current="request()->routeIs('categories.*')">
                    {{ __('Kategori') }}
                </flux:sidebar.item>
                @endcan

                @can('brands.view')
                <flux:sidebar.item icon="tag" href="/brands"
                    :current="request()->routeIs('brands.*')">
                    {{ __('Brand') }}
                </flux:sidebar.item>
                @endcan
                @endif

                @if ($featureManager->isEnabled(App\Enums\Module::Suppliers))
                @can('suppliers.view')
                <flux:sidebar.item icon="building-storefront" href="/suppliers"
                    :current="request()->routeIs('suppliers.*')">
                    {{ __('Supplier') }}
                </flux:sidebar.item>
                @endcan
                @endif

                @if ($featureManager->isEnabled(App\Enums\Module::Customers))
                @can('customers.view')
                <flux:sidebar.item icon="users" href="/customers"
                    :current="request()->routeIs('customers.*')">
                    {{ __('Pelanggan') }}
                </flux:sidebar.item>
                @endcan
                @endif
            </flux:sidebar.group>
            @endif

            {{-- Inventory --}}
            @if ($featureManager && $featureManager->isEnabled(App\Enums\Module::Stock))
            <flux:sidebar.group :heading="__('Inventory')" class="grid mt-4">
                @can('stock.view')
                <flux:sidebar.item icon="archive-box" href="/stock-movements"
                    :current="request()->routeIs('stock-movements.*')">
                    {{ __('Mutasi Stok') }}
                </flux:sidebar.item>
                @endcan

                @can('opname.view')
                <flux:sidebar.item icon="clipboard-document-list" href="/stock-opnames"
                    :current="request()->routeIs('stock-opnames.*')">
                    {{ __('Stock Opname') }}
                </flux:sidebar.item>
                @endcan
            </flux:sidebar.group>
            @endif

            {{-- Laporan --}}
            @if ($featureManager && ($featureManager->isEnabled(App\Enums\Module::Reports) || $featureManager->isEnabled(App\Enums\Module::ReportsAdvanced)))
            <flux:sidebar.group :heading="__('Laporan')" class="grid mt-4">
                <flux:sidebar.item icon="chart-bar-square" href="{{ route('reports.index') }}">
                    {{ __('Laporan') }}
                </flux:sidebar.item>
            </flux:sidebar.group>
            @endif

            {{-- Admin --}}
            @can('users.view')
            <flux:sidebar.group :heading="__('Pengaturan')" class="grid mt-4">
                <flux:sidebar.item icon="users" href="/users"
                    :current="request()->routeIs('users.*')">
                    {{ __('Users') }}
                </flux:sidebar.item>

                @can('roles.view')
                <flux:sidebar.item icon="shield-check" href="/roles"
                    :current="request()->routeIs('roles.*')">
                    {{ __('Roles') }}
                </flux:sidebar.item>
                @endcan

                @can('permissions.view')
                <flux:sidebar.item icon="key" href="/permissions"
                    :current="request()->routeIs('permissions.*')">
                    {{ __('Permissions') }}
                </flux:sidebar.item>
                @endcan

                @can('settings.store')
                <flux:sidebar.item icon="cog-6-tooth" href="/settings/store"
                    :current="request()->routeIs('settings.store')">
                    {{ __('Store Settings') }}
                </flux:sidebar.item>
                @endcan

                @can('payment_gateway.configure')
                <flux:sidebar.item icon="qr-code" href="{{ route('settings.payment') }}"
                    :current="request()->routeIs('settings.payment')">
                    {{ __('Pengaturan Pembayaran') }}
                </flux:sidebar.item>
                @endcan
            </flux:sidebar.group>
            @endcan

            {{-- Sinkronisasi (§11.5: jumlah operasi tertunda + konflik) --}}
            <flux:sidebar.group :heading="__('Sistem')" class="grid mt-4">
                @can('user.manage')
                <flux:sidebar.item icon="clipboard-document-list" href="{{ route('audit.index') }}"
                    :current="request()->routeIs('audit.*')">
                    {{ __('Jejak Audit') }}
                </flux:sidebar.item>
                @endcan

                @can('backup.restore')
                <flux:sidebar.item icon="circle-stack" href="{{ route('backup.index') }}"
                    :current="request()->routeIs('backup.*')">
                    {{ __('Backup & Restore') }}
                </flux:sidebar.item>
                @endcan

                <flux:sidebar.item icon="arrow-path" href="{{ route('sync.index') }}"
                    :current="request()->routeIs('sync.*')">
                    {{ __('Sinkronisasi') }}
                </flux:sidebar.item>
            </flux:sidebar.group>
        </flux:sidebar.nav>

        <flux:spacer />

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
