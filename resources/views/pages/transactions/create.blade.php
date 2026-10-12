<?php

use App\Actions\Sales\CreateSale;
use App\Enums\PaymentMethod;
use App\Enums\PaymentVerification;
use App\Exceptions\Payments\QrisNotConfigured;
use App\Exceptions\Sales\InsufficientStock;
use App\Exceptions\Sales\InvalidPaymentAmount;
use App\Exceptions\Sales\InvalidPaymentVerification;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Support\Money\Money;
use App\Support\Qr\SvgQrCode;
use Flux\Flux;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\WithPagination;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\usesPagination;

usesPagination();

name('transactions.create');
middleware('auth');
middleware('verified');

state([
    'customer_name' => '',
    'customer_id' => null,
    'customer_search' => '',
    'category_id' => (int) '',
    'productSearch' => '',
    'cart' => [],
    'paid_amount' => 0,
    'discount_type' => 'none', // none | percent | nominal (FR-06)
    'discount_value' => 0,
    'notes' => '',
    'client_request_id' => '',
    'showConfirmModal' => false,
    'showUnitModal' => false,
    'showSuccessModal' => false,
    'selectedProduct' => null,
    'selectedUnitId' => null,
    'savedTransactionId' => null,
    'savedInvoiceNumber' => '',
]);

/*
 * Metode pembayaran diikat ke URL (dan bukan state biasa) agar pilihan QRIS
 * dapat dialamatkan/diuji lewat GET, mis.
 * /transactions/create?payment_method=qris_static. Ini juga membuat tautan
 * kasir ke mode QRIS dapat disimpan/dibagikan.
 */
state(['payment_method' => PaymentMethod::Cash->value])->url();

$categoryOptions = computed(fn () => Category::orderBy('name')->get());

$filteredCustomers = computed(function () {
    if (strlen($this->customer_search ?? '') < 1) {
        return collect();
    }

    return Customer::where('name', 'like', '%' . $this->customer_search . '%')
        ->orderBy('name')->limit(10)->get();
});

$products = computed(function () {
    $query = Product::query()->with('units')->where('is_active', true);

    if (! empty($this->category_id)) {
        $query->where('category_id', $this->category_id);
    }

    if (! empty($this->productSearch)) {
        $query->where(function ($q) {
            $q->where('name', 'like', '%' . $this->productSearch . '%')
              ->orWhere('sku', 'like', '%' . $this->productSearch . '%')
              ->orWhere('barcode', 'like', '%' . $this->productSearch . '%');
        });
    }

    return $query->orderBy('name')->paginate(12);
});

/*
 * Money: minor units (IDR exponent 0 -> minor == rupiah). The cart still
 * carries decimal floats for the transitional UI; the domain action is fed
 * integer minor units only.
 */
$subtotalMinor = computed(fn () => (int) round((float) collect($this->cart)->sum('subtotal')));

$discountMinor = computed(function () {
    if ($this->discount_type === 'percent' && $this->discount_value > 0) {
        return (int) round($this->subtotalMinor * (float) $this->discount_value / 100);
    }

    if ($this->discount_type === 'nominal' && $this->discount_value > 0) {
        return (int) round((float) $this->discount_value);
    }

    return 0;
});

$totalMinor = computed(fn () => max(0, $this->subtotalMinor - $this->discountMinor));

$isCredit = computed(fn () => $this->changeMinor < 0);

/*
 * QRIS statis (FR-05): nominal QR sudah tetap, jadi "dibayar" = total secara
 * konstruksi — kasir tidak mengetik nominal dan tidak ada kembalian. Semua
 * perhitungan (kembalian, kredit, nominal yang dikirim ke domain) memakai
 * `effectivePaidMinor` agar tampilan dan data tersimpan tidak pernah berbeda.
 */
$isQrisStatic = computed(fn () => $this->payment_method === PaymentMethod::QrisStatic->value);

$effectivePaidMinor = computed(function () {
    if ($this->isQrisStatic) {
        return $this->totalMinor;
    }

    return Money::fromMajor((float) ($this->paid_amount ?: 0))->minor();
});

$changeMinor = computed(fn () => $this->effectivePaidMinor - $this->totalMinor);

$qrisTenant = computed(function () {
    $tenantId = auth()->user()?->tenant_id;

    return $tenantId === null ? null : Tenant::find($tenantId);
});

$qrisConfigured = computed(fn () => (bool) $this->qrisTenant?->hasQrisConfigured());

$qrisSvg = computed(function () {
    $tenant = $this->qrisTenant;

    if (! $tenant?->hasQrisConfigured()) {
        return null;
    }

    return SvgQrCode::render(trim((string) $tenant->qris_payload), 240);
});

$openUnitSelector = function ($productId, $unitId = null) {
    $this->selectedProduct = Product::with('units')->findOrFail($productId);

    $unit = null;
    if ($unitId !== null) {
        $unit = $this->selectedProduct->units()->where('is_active', true)->find($unitId);
    }

    $this->selectedUnitId = $unit?->id ?? $this->selectedProduct->units()->where('is_active', true)->first()?->id;
    $this->showUnitModal = true;
};

/*
 * FR-03 barcode kamera: hasil decode dari browser dikirim ke sini.
 * - selalu perbarui pencarian agar hasil scan terlihat di grid;
 * - barcode produk yang cocok persis → buka pemilih satuan (alur "+");
 * - barcode satuan (product_units.barcode) yang cocok → buka pemilih satuan
 *   dengan satuan itu terpilih sejak awal;
 * - tidak cocok → peringatan; kasir tetap bisa mencari/menambah manual.
 */
$scanBarcode = function (string $barcode) {
    $barcode = trim($barcode);

    if ($barcode === '') {
        return;
    }

    $this->productSearch = $barcode;

    $product = Product::query()
        ->where('is_active', true)
        ->where('barcode', $barcode)
        ->first();

    if ($product !== null) {
        $this->openUnitSelector($product->id);

        return;
    }

    $unit = ProductUnit::query()
        ->where('is_active', true)
        ->where('barcode', $barcode)
        ->whereHas('product', fn ($q) => $q->where('is_active', true))
        ->first();

    if ($unit !== null) {
        $this->openUnitSelector($unit->product_id, $unit->id);

        return;
    }

    Flux::toast(variant: 'warning', text: __('Tidak ada produk aktif dengan barcode ":code".', ['code' => $barcode]));
};

$addToCart = function () {
    $product = $this->selectedProduct;
    if (! $product || ! $this->selectedUnitId) {
        Flux::toast(variant: 'error', text: __('Please select a unit.'));
        return;
    }
    $unit = ProductUnit::findOrFail($this->selectedUnitId);

    $cartKey = $product->id . '-' . $unit->id;

    foreach ($this->cart as &$item) {
        if ($item['cart_key'] === $cartKey) {
            $item['quantity']++;
            $item['subtotal'] = $item['quantity'] * $item['unit_price'];
            $this->showUnitModal = false;
            $this->selectedProduct = null;
            $this->paid_amount = $this->totalMinor;
            return;
        }
    }

    $this->cart[] = [
        'cart_key' => $cartKey,
        'product_id' => $product->id,
        'product_unit_id' => $unit->id,
        'unit_name' => $unit->name,
        'unit_abbreviation' => $unit->abbreviation,
        'conversion_factor' => (float) $unit->conversion_factor,
        'name' => $product->name . ' (' . $unit->abbreviation . ')',
        'unit_price' => (float) $unit->price,
        'quantity' => 1,
        'subtotal' => (float) $unit->price,
    ];

    $this->showUnitModal = false;
    $this->selectedProduct = null;
    $this->paid_amount = $this->totalMinor;
};

$incrementQty = function ($index) {
    if (isset($this->cart[$index])) {
        $this->cart[$index]['quantity']++;
        $this->cart[$index]['subtotal'] = $this->cart[$index]['quantity'] * $this->cart[$index]['unit_price'];
        $this->paid_amount = $this->totalMinor;
    }
};

$decrementQty = function ($index) {
    if (isset($this->cart[$index])) {
        if ($this->cart[$index]['quantity'] <= 1) {
            array_splice($this->cart, $index, 1);
        } else {
            $this->cart[$index]['quantity']--;
            $this->cart[$index]['subtotal'] = $this->cart[$index]['quantity'] * $this->cart[$index]['unit_price'];
        }
        $this->paid_amount = $this->totalMinor;
    }
};

$removeFromCart = function ($index) {
    array_splice($this->cart, $index, 1);
    $this->paid_amount = $this->totalMinor;
};

$selectCustomer = function ($id, $name) {
    $this->customer_id = $id;
    $this->customer_name = $name;
    $this->customer_search = '';
};

$clearCustomer = function () {
    $this->customer_id = null;
    $this->customer_name = '';
    $this->customer_search = '';
};

$confirmSave = function () {
    if (empty($this->cart)) {
        Flux::toast(variant: 'error', text: __('Please add at least one product.'));
        return;
    }

    $this->validate([
        'paid_amount' => 'required|numeric|min:0',
        'payment_method' => ['required', 'string', Rule::enum(PaymentMethod::class)],
        'discount_type' => 'required|string|in:none,percent,nominal',
        'discount_value' => 'nullable|numeric|min:0',
        'notes' => 'nullable|string|max:500',
    ]);

    // FR-05: tidak ada pembayaran QRIS tanpa QRIS yang diunggah pemilik.
    if ($this->isQrisStatic && ! $this->qrisConfigured) {
        Flux::toast(variant: 'error', text: __('QRIS is not configured for this store.'));

        return;
    }

    if ($this->isQrisStatic && $this->totalMinor <= 0) {
        Flux::toast(variant: 'error', text: __('Add products before recording a QRIS payment.'));

        return;
    }

    // Credit sales need a customer (FR-07).
    if ($this->isCredit && ! $this->customer_id) {
        Flux::toast(variant: 'error', text: __('Select customer for credit sale.'));
        return;
    }

    // Idempotency key: one per logical checkout attempt (FR-04).
    if ($this->client_request_id === '') {
        $this->client_request_id = (string) Str::uuid7();
    }

    $this->showConfirmModal = true;
};

$save = function () {
    try {
        $items = collect($this->cart)->map(fn ($item) => [
            'product_id' => (int) $item['product_id'],
            'product_unit_id' => isset($item['product_unit_id']) ? (int) $item['product_unit_id'] : null,
            'unit_name' => $item['unit_name'] ?? null,
            'quantity' => (int) $item['quantity'],
            'unit_price_minor' => Money::fromMajor((float) $item['unit_price'])->minor(),
            'conversion_factor' => (float) ($item['conversion_factor'] ?? 1),
        ])->values()->all();

        $transaction = app(CreateSale::class)->handle([
            'user_id' => auth()->id(),
            // Sesi web tidak mengikat TenantContext (lihat AuthenticateDevice untuk
            // jalur perangkat), jadi tenant penjualan diambil dari kasir yang aktif.
            // Tanpa ini penjualan dari POS akan tersimpan tanpa tenant.
            'tenant_id' => auth()->user()?->tenant_id,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer_name ?: 'Umum',
            'payment_method' => $this->payment_method,
            'payment_verification' => PaymentVerification::CashierRecorded->value,
            'paid_minor' => $this->effectivePaidMinor,
            'discount_percent' => $this->discount_type === 'percent' ? (float) $this->discount_value : null,
            'discount_minor' => $this->discount_type === 'nominal' ? (int) $this->discount_value : null,
            'client_request_id' => $this->client_request_id ?: null,
            'notes' => $this->notes ?? '',
            'items' => $items,
        ]);

        // Success: the sale is committed before the cart empties (FR-04).
        $this->reset(['customer_name', 'customer_id', 'customer_search', 'category_id', 'productSearch', 'cart', 'paid_amount', 'payment_method', 'discount_type', 'discount_value', 'notes', 'client_request_id', 'showConfirmModal']);

        $this->savedTransactionId = $transaction->id;
        $this->savedInvoiceNumber = $transaction->invoice_number;
        $this->showSuccessModal = true;
    } catch (InsufficientStock|InvalidPaymentAmount|InvalidPaymentVerification|QrisNotConfigured|\InvalidArgumentException $e) {
        // Failure must never empty the cart (FR-04).
        $this->showConfirmModal = false;
        Flux::toast(variant: 'error', text: $e->getMessage());
    }
};

?>

<x-layouts::app :title="__('New Transaction')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl"
            x-data="{
                scanning: false,
                cameraMessage: '',
                startScanning() {
                    this.scanning = true;
                    this.cameraMessage = '';
                    const video = document.getElementById('pos-barcode-video');
                    window.posBarcodeScanner?.open({
                        video,
                        onResult: (code) => {
                            this.scanning = false;
                            $wire.scanBarcode(code);
                        },
                        onError: () => {
                            this.cameraMessage = '{{ __('Kamera tidak tersedia. Gunakan input manual.') }}';
                        },
                    });
                },
                stopScanning() {
                    this.scanning = false;
                    window.posBarcodeScanner?.close();
                },
            }">
            @php
                // Katalog FR-05 (sumber tunggal: enum PaymentMethod), termasuk
                // QRIS statis. Label aman untuk nilai lama lewat labelFor().
                $paymentMethods = \App\Enums\PaymentMethod::cases();
            @endphp

            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item href="{{ route('transactions.index') }}">{{ __('Transactions') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('New Transaction') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div>
                <flux:heading size="xl">{{ __('New Transaction') }}</flux:heading>
                <flux:subheading>{{ __('Select products and process payment.') }}</flux:subheading>
            </div>

            <form wire:submit="confirmSave">
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {{-- LEFT COLUMN (2/3): Product Selection --}}
                    <div class="space-y-6 lg:col-span-2">
                        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                            <div class="mb-6">
                                <flux:heading size="lg">{{ __('Select Products') }}</flux:heading>
                                <flux:subheading>{{ __('Search and choose products to add to the transaction.') }}</flux:subheading>
                            </div>

                            <div class="mb-4 grid gap-4 sm:grid-cols-2">
                                <flux:select wire:model.live="category_id" :label="__('Category')" placeholder="{{ __('All categories') }}">
                                    @foreach ($this->categoryOptions as $category)
                                        <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>

                                <div class="flex items-end gap-2">
                                    <flux:input wire:model.live="productSearch" :label="__('Search')" type="search"
                                        placeholder="{{ __('Search by name, SKU, or barcode...') }}" class="flex-1" />
                                    <flux:button icon="camera" class="shrink-0" x-on:click="startScanning()"
                                        :title="__('Scan barcode dengan kamera')" :aria-label="__('Scan barcode dengan kamera')" />
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                @forelse ($this->products as $product)
                                    <div
                                        class="group relative flex items-end justify-between rounded-lg border border-zinc-200 p-3 transition-all hover:scale-[1.02] hover:border-zinc-400 hover:shadow-sm active:scale-[0.98] dark:border-zinc-700 dark:hover:border-zinc-500"
                                        style="background-image: linear-gradient(to top, rgba(0,0,0,0.7) 0%, rgba(0,0,0,0.1) 60%, rgba(0,0,0,0.05) 100%), url('{{ $product->image_url }}'); background-size: cover; background-position: center; min-height: 100px;">
                                        <div class="min-w-0 flex-1 z-10">
                                            <p class="truncate text-sm font-medium text-white drop-shadow-sm">{{ $product->name }}</p>
                                            <p class="text-xs text-zinc-200 drop-shadow-sm">
                                                @php $baseUnit = $product->units()->where('is_base', true)->first(); @endphp
                                                @if ($baseUnit)
                                                    {{ Number::currency($baseUnit->price, 'IDR', 'id') }}/{{ $baseUnit->abbreviation }}
                                                @else
                                                    {{ Number::currency($product->price, 'IDR', 'id') }}
                                                @endif
                                            </p>
                                            <div class="mt-1">
                                            @if($product->is_unlimited_stock)
                                                <flux:badge size="xs" color="purple" inset="top bottom">{{ __('Tanpa Stok') }}</flux:badge>
                                            @elseif ($product->stock < 1)
                                                <flux:badge size="xs" color="red" inset="top bottom">{{ __('out of stock') }}</flux:badge>
                                            @elseif ($product->min_stock > 0 && $product->stock <= $product->min_stock)
                                                <flux:badge size="xs" color="orange" inset="top bottom">{{ $product->total_stock_label }} {{ __('left') }}</flux:badge>
                                            @endif
                                            </div>
                                        </div>
                                        <flux:button size="xs" variant="primary" icon="plus"
                                            wire:click="openUnitSelector({{ $product->id }})"
                                            :disabled="!$product->is_unlimited_stock && $product->stock < 1"
                                            class="shrink-0 z-10" />
                                    </div>
                                @empty
                                    <div class="col-span-full rounded-lg border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-600">
                                        {{ __('No products found.') }}
                                    </div>
                                @endforelse
                            </div>

                            <div class="mt-4">
                                {{ $this->products->links(data: ['navigate' => true]) }}
                            </div>
                        </div>
                    </div>

                    {{-- RIGHT COLUMN (1/3): Checkout Panel --}}
                    <div class="space-y-5 lg:col-span-1">
                        <div class="sticky top-6 space-y-5">
                            {{-- Order Summary --}}
                            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                                <flux:heading size="lg">{{ __('Order Summary') }}</flux:heading>

                                <div class="mt-4 space-y-4">
                                    {{-- Customer Autocomplete --}}
                                    <div class="space-y-2" wire:key="customer-autocomplete">
                                        <flux:label>{{ __('Customer') }}</flux:label>
                                        <div class="relative">
                                            <flux:input wire:model.live="customer_search" placeholder="{{ __('Cari pelanggan... (kosongkan untuk tanpa nama)') }}" />
                                            @if ($customer_name)
                                                <button type="button" wire:click="clearCustomer"
                                                    class="absolute right-2 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-red-500">
                                                    <flux:icon name="x-mark" class="h-4 w-4" />
                                                </button>
                                            @endif
                                            @if (strlen($customer_search ?? '') > 0 && !$customer_name)
                                                <div class="absolute z-10 mt-1 w-full rounded-lg border border-zinc-200 bg-white shadow-lg dark:border-zinc-700 dark:bg-zinc-800">
                                                    @forelse ($this->filteredCustomers as $c)
                                                        <button type="button" wire:click="selectCustomer({{ $c->id }}, '{{ $c->name }}')"
                                                            class="w-full px-3 py-2 text-left text-sm transition hover:bg-zinc-100 dark:hover:bg-zinc-700">
                                                            {{ $c->name }}
                                                        </button>
                                                    @empty
                                                        <div class="px-3 py-2 text-sm text-zinc-500">{{ __('Pelanggan baru: :name', ['name' => $customer_search]) }}</div>
                                                    @endforelse
                                                </div>
                                            @endif
                                        </div>
                                        @if ($customer_name)
                                            <p class="text-xs text-zinc-500">{{ __('Pelanggan: :name', ['name' => $customer_name]) }}</p>
                                        @endif
                                    </div>

                                    {{-- Cart Items --}}
                                    @if (count($this->cart) > 0)
                                        <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                            @foreach ($this->cart as $index => $item)
                                                <div class="flex items-center justify-between gap-2 py-2 first:pt-0 last:pb-0">
                                                    <div class="min-w-0 flex-1">
                                                        <p class="truncate text-sm font-medium">{{ $item['name'] }}</p>
                                                        <p class="text-xs text-zinc-500">{{ Number::currency($item['unit_price'], 'IDR', 'id') }}</p>
                                                    </div>
                                                    <div class="flex items-center gap-1">
                                                        <flux:button size="xs" variant="ghost" icon="minus"
                                                            wire:click="decrementQty({{ $index }})" class="shrink-0" />
                                                        <span class="w-5 text-center text-sm font-medium">{{ $item['quantity'] }}</span>
                                                        <flux:button size="xs" variant="ghost" icon="plus"
                                                            wire:click="incrementQty({{ $index }})" class="shrink-0" />
                                                    </div>
                                                    <div class="min-w-[4.5rem] text-right text-sm font-medium">
                                                        {{ Number::currency($item['subtotal'], 'IDR', 'id') }}
                                                    </div>
                                                    <button type="button" wire:click="removeFromCart({{ $index }})"
                                                        class="shrink-0 rounded-full p-1 text-zinc-400 transition-colors hover:bg-red-50 hover:text-red-500 dark:hover:bg-red-900/20">
                                                        <flux:icon name="x-mark" class="h-3.5 w-3.5" />
                                                    </button>
                                                </div>
                                            @endforeach
                                        </div>

                                        <div class="flex items-center justify-between border-t border-zinc-200 pt-3 text-base font-bold dark:border-zinc-700">
                                            <span>{{ __('Subtotal') }}</span>
                                            <span class="text-blue-600 dark:text-blue-400">{{ Number::currency($this->subtotalMinor, 'IDR', 'id') }}</span>
                                        </div>

                                        @if ($this->discountMinor > 0)
                                            <div class="flex items-center justify-between text-sm text-green-600 dark:text-green-400">
                                                <span>{{ __('Discount') }}</span>
                                                <span>-{{ Number::currency($this->discountMinor, 'IDR', 'id') }}</span>
                                            </div>
                                        @endif

                                        <div class="flex items-center justify-between border-t border-zinc-200 pt-2 text-base font-bold dark:border-zinc-700">
                                            <span>{{ __('Total') }}</span>
                                            <span class="text-blue-600 dark:text-blue-400">{{ Number::currency($this->totalMinor, 'IDR', 'id') }}</span>
                                        </div>
                                    @else
                                        <div class="rounded-lg border border-dashed border-zinc-300 p-4 text-center text-sm text-zinc-500 dark:border-zinc-600">
                                            {{ __('No products added yet.') }}
                                        </div>
                                    @endif
                                </div>
                            </div>

                            {{-- Payment --}}
                            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                                <flux:heading size="lg">{{ __('Payment') }}</flux:heading>
                                <div class="mt-4 space-y-3">
                                    <flux:select wire:model.live="payment_method" :label="__('Method')">
                                        @foreach ($paymentMethods as $method)
                                            <flux:select.option value="{{ $method->value }}">{{ $method->label() }}</flux:select.option>
                                        @endforeach
                                    </flux:select>

                                    @if ($this->isQrisStatic)
                                        {{-- FR-05: QRIS statis = instruksi pembayaran; nominal terkunci --}}
                                        <div class="rounded-lg border border-teal-200 bg-teal-50 p-3 dark:border-teal-700 dark:bg-teal-900/20">
                                            @if ($this->qrisConfigured)
                                                <p class="text-sm font-medium text-teal-800 dark:text-teal-200">{{ __('Scan this QRIS to pay') }}</p>
                                                <div class="mt-2 flex items-start gap-4">
                                                    <div class="shrink-0 rounded bg-white p-2">{!! $this->qrisSvg !!}</div>
                                                    <div class="space-y-1 text-sm">
                                                        <p class="font-medium">{{ $this->qrisTenant?->qrisMerchantDisplayName() }}</p>
                                                        <p>{{ __('Amount') }}: <span class="font-medium">{{ Number::currency($this->totalMinor, 'IDR', 'id') }}</span></p>
                                                        <p class="text-xs text-zinc-600 dark:text-zinc-300">
                                                            {{ __('The cashier records this payment; the system does not automatically verify it.') }}
                                                        </p>
                                                    </div>
                                                </div>
                                            @else
                                                <p class="text-sm font-medium text-red-700 dark:text-red-300">
                                                    {{ __('QRIS is not configured for this store. Ask the owner to upload it in Payment Settings.') }}
                                                </p>
                                            @endif
                                        </div>
                                    @endif

                                    @if ($this->isQrisStatic && $this->qrisConfigured)
                                        <div>
                                            <flux:label>{{ __('Paid Amount') }}</flux:label>
                                            <p class="mt-1 font-medium">
                                                {{ Number::currency($this->effectivePaidMinor, 'IDR', 'id') }}
                                                <span class="text-xs text-zinc-500">({{ __('locked to the total') }})</span>
                                            </p>
                                        </div>
                                    @elseif (! $this->isQrisStatic)
                                        <flux:input wire:model.live="paid_amount" :label="__('Paid Amount')" type="number" step="0.01" min="0" />
                                    @endif

                                    <div class="grid grid-cols-2 gap-3">
                                        <flux:select wire:model="discount_type" :label="__('Discount')">
                                            <flux:select.option value="none">{{ __('None') }}</flux:select.option>
                                            <flux:select.option value="percent">{{ __('Percent') }}</flux:select.option>
                                            <flux:select.option value="nominal">{{ __('Nominal') }}</flux:select.option>
                                        </flux:select>

                                        <flux:input wire:model="discount_value" :label="__('Discount Value')" type="number"
                                            step="0.01" min="0"
                                            :placeholder="$this->discount_type === 'percent' ? '0-100' : '0'" />
                                    </div>

                                    <div>
                                        <flux:label>{{ __('Change') }}</flux:label>
                                        <div @class([
                                            'mt-1 text-lg font-bold',
                                            'text-green-600 dark:text-green-400' => $this->changeMinor >= 0,
                                            'text-red-600 dark:text-red-400' => $this->changeMinor < 0,
                                        ])>
                                            {{ Number::currency($this->changeMinor, 'IDR', 'id') }}
                                        </div>
                                        @if ($this->isCredit)
                                            <p class="mt-1 text-xs text-red-500">{{ __('Credit sale — select a customer.') }}</p>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            {{-- Notes --}}
                            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                                <flux:textarea wire:model="notes" :label="__('Notes')" placeholder="{{ __('Optional...') }}" rows="2" />
                            </div>

                            {{-- Actions --}}
                            <div class="flex gap-2">
                                <flux:button href="{{ route('transactions.index') }}" variant="filled" class="flex-1 justify-center">
                                    {{ __('Cancel') }}
                                </flux:button>
                                <flux:button type="submit" variant="primary" :disabled="empty($this->cart)" class="flex-1 justify-center">
                                    {{ __('Review & Confirm') }}
                                </flux:button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

            {{-- Unit Selector Modal --}}
            <flux:modal wire:model.self="showUnitModal" class="max-w-md">
                @if ($selectedProduct)
                    <form wire:submit="addToCart" class="space-y-6">
                        <div>
                            <flux:heading size="lg">{{ __('Pilih Satuan') }}</flux:heading>
                            <flux:subheading>{{ $selectedProduct->name }}</flux:subheading>
                        </div>

                        <div class="space-y-2">
                            @php $activeUnits = $selectedProduct->units()->where('is_active', true)->get(); @endphp
                            @forelse ($activeUnits as $unit)
                                <label wire:key="unit-{{ $unit->id }}" class="flex cursor-pointer items-center gap-3 rounded-lg border border-zinc-200 p-3 transition hover:border-blue-400 has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50 dark:border-zinc-700 dark:has-[:checked]:border-blue-500 dark:has-[:checked]:bg-blue-900/20">
                                    <input type="radio" name="unit" value="{{ $unit->id }}" wire:model="selectedUnitId"
                                        class="h-4 w-4 text-blue-600">
                                    <div class="flex-1">
                                        <p class="text-sm font-medium">{{ $unit->name }} ({{ $unit->abbreviation }})</p>
                                        <p class="text-xs text-zinc-500">{{ Number::currency($unit->price, 'IDR', 'id') }}</p>
                                    </div>
                                    @if ($unit->is_base)
                                        <flux:badge size="xs" color="blue" inset="top bottom">{{ __('Base') }}</flux:badge>
                                    @endif
                                </label>
                            @empty
                                <p class="text-sm text-zinc-500">{{ __('No active units for this product.') }}</p>
                            @endforelse
                        </div>

                        <div class="flex justify-end gap-2">
                            <flux:modal.close>
                                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                            </flux:modal.close>
                            <flux:button variant="primary" type="submit">{{ __('Add to Cart') }}</flux:button>
                        </div>
                    </form>
                @endif
            </flux:modal>

            {{-- Confirmation Modal --}}
            <flux:modal wire:model.self="showConfirmModal" class="max-w-2xl">
                <form wire:submit="save" class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Confirm Transaction') }}</flux:heading>
                        <flux:subheading>{{ __('Please review the transaction details below before saving.') }}</flux:subheading>
                    </div>

                    @if ($customer_name)
                        <div class="text-sm">
                            <span class="text-zinc-500">{{ __('Customer') }}:</span>
                            <span class="ml-2 font-medium">{{ $customer_name }}</span>
                        </div>
                    @endif

                    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                                    <th class="px-3 py-2 text-left font-medium">{{ __('Product') }}</th>
                                    <th class="px-3 py-2 text-center font-medium">{{ __('Qty') }}</th>
                                    <th class="px-3 py-2 text-right font-medium">{{ __('Price') }}</th>
                                    <th class="px-3 py-2 text-right font-medium">{{ __('Subtotal') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->cart as $item)
                                    <tr class="border-b border-zinc-100 last:border-b-0 dark:border-zinc-800">
                                        <td class="px-3 py-2">{{ $item['name'] }}</td>
                                        <td class="px-3 py-2 text-center">{{ $item['quantity'] }} {{ $item['unit_abbreviation'] }}</td>
                                        <td class="px-3 py-2 text-right">{{ Number::currency($item['unit_price'], 'IDR', 'id') }}</td>
                                        <td class="px-3 py-2 text-right font-medium">{{ Number::currency($item['subtotal'], 'IDR', 'id') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="border-t bg-zinc-50 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                                    <td colspan="3" class="px-3 py-2 text-right">{{ __('Subtotal') }}</td>
                                    <td class="px-3 py-2 text-right">{{ Number::currency($this->subtotalMinor, 'IDR', 'id') }}</td>
                                </tr>
                                @if ($this->discountMinor > 0)
                                    <tr class="bg-zinc-50 text-sm text-green-600 dark:bg-zinc-800 dark:text-green-400">
                                        <td colspan="3" class="px-3 py-2 text-right">{{ __('Discount') }}</td>
                                        <td class="px-3 py-2 text-right">-{{ Number::currency($this->discountMinor, 'IDR', 'id') }}</td>
                                    </tr>
                                @endif
                                <tr class="border-t bg-zinc-50 font-semibold dark:border-zinc-700 dark:bg-zinc-800">
                                    <td colspan="3" class="px-3 py-2 text-right">{{ __('Total') }}</td>
                                    <td class="px-3 py-2 text-right">{{ Number::currency($this->totalMinor, 'IDR', 'id') }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <div class="grid grid-cols-3 gap-4 text-sm">
                        <div>
                            <span class="text-zinc-500">{{ __('Payment') }}</span>
                            <p class="font-medium">{{ \App\Enums\PaymentMethod::labelFor($payment_method) }}</p>
                        </div>
                        <div>
                            <span class="text-zinc-500">{{ __('Paid') }}</span>
                            <p class="font-medium">{{ Number::currency((float) ($paid_amount ?: 0), 'IDR', 'id') }}</p>
                        </div>
                        <div>
                            <span class="text-zinc-500">{{ __('Change') }}</span>
                            <p class="font-medium">{{ Number::currency($this->changeMinor, 'IDR', 'id') }}</p>
                        </div>
                    </div>

                    @if ($this->isCredit && $customer_name)
                        <div class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
                            {{ __('This is a credit sale. The unpaid balance will be added to the customer account.') }}
                        </div>
                    @endif

                    @if ($notes)
                        <div class="text-sm">
                            <span class="text-zinc-500">{{ __('Notes') }}:</span>
                            <p class="mt-1">{{ $notes }}</p>
                        </div>
                    @endif

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="save">
                            <span wire:loading.remove wire:target="save">{{ __('Confirm & Save') }}</span>
                            <span wire:loading wire:target="save">{{ __('Saving...') }}</span>
                        </flux:button>
                    </div>
                </form>
            </flux:modal>

            {{-- Success Modal --}}
            <flux:modal wire:model.self="showSuccessModal" class="max-w-md">
                <div class="space-y-6">
                    <div class="text-center">
                        <flux:icon name="check-circle" class="mx-auto h-12 w-12 text-green-500" />
                        <flux:heading size="lg" class="mt-2">{{ __('Transaction Success') }}</flux:heading>
                        <flux:subheading>{{ $savedInvoiceNumber }}</flux:subheading>
                    </div>

                    <div class="flex flex-col gap-2">
                        <flux:button variant="primary" class="justify-center" icon="printer"
                            :href="'/transactions/' . $savedTransactionId . '/receipt'" navigate>
                            {{ __('Cetak Struk') }}
                        </flux:button>
                        <flux:button variant="filled" class="justify-center"
                            :href="'/transactions/create'" navigate>
                            {{ __('Transaksi Baru') }}
                        </flux:button>
                    </div>
                </div>
            </flux:modal>

            {{-- Camera Barcode Scanner Overlay (FR-03 §20). Decode berjalan di browser;
                 bila kamera tidak tersedia/tolak izin, input manual tetap tersedia. --}}
            <div x-show="scanning" x-cloak x-transition.opacity.duration.200ms
                class="fixed inset-0 z-50 flex items-center justify-center bg-zinc-950/80 p-4"
                x-on:click.self="stopScanning()">
                <div class="w-full max-w-lg rounded-2xl bg-white p-5 shadow-2xl dark:bg-zinc-800">
                    <div class="mb-4 flex items-center justify-between">
                        <flux:heading size="md">{{ __('Scan Barcode') }}</flux:heading>
                        <flux:button variant="ghost" size="sm" icon="x-mark" x-on:click="stopScanning()" />
                    </div>
                    <video id="pos-barcode-video" class="mx-auto aspect-video w-full rounded-xl bg-zinc-950 object-cover" playsinline muted autoplay></video>
                    <p x-show="cameraMessage" x-text="cameraMessage" class="mt-3 text-center text-sm text-zinc-500"></p>
                    <div class="mt-4 flex justify-end gap-2">
                        <flux:button variant="primary" x-on:click="stopScanning()">{{ __('Close') }}</flux:button>
                    </div>
                </div>
            </div>
        </div>
    @endvolt
</x-layouts::app>