<?php

use App\Actions\Sales\VoidSale;
use App\Exceptions\Auth\ActionForbidden;
use App\Exceptions\Sales\VoidNotAllowed;
use App\Models\Transaction;
use Flux\Flux;
use Livewire\WithPagination;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

name('transactions.index');

middleware('auth');
middleware('verified');

uses(WithPagination::class);

state([
    'search' => '',
    'sortBy' => 'id',
    'sortDirection' => 'desc',
])->url();

state([
    'showDetailModal' => false,
    'showVoidModal' => false,
    'voidingTransactionId' => null,
    'voidReason' => '',
    'viewingTransactionId' => null,
]);

$sort = function ($column) {
    if ($this->sortBy === $column) {
        $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
    } else {
        $this->sortBy = $column;
        $this->sortDirection = 'asc';
    }
};

$transactions = computed(function () {
    return Transaction::query()
        ->withCount('items')
        ->where(function ($q) {
            $q->where('invoice_number', 'like', '%'.$this->search.'%')->orWhere('customer_name', 'like', '%'.$this->search.'%');
        })
        ->orderBy($this->sortBy, $this->sortDirection)
        ->paginate(10);
});

$viewTransaction = computed(function () {
    if (! $this->viewingTransactionId) {
        return null;
    }

    return Transaction::with('items.product')->find($this->viewingTransactionId);
});

$viewDetail = function ($id) {
    $this->viewingTransactionId = $id;
    $this->showDetailModal = true;
};

/**
 * §17.4 "Retur dan void": transaksi selesai tidak dihapus permanen.
 * Pembatalan terkontrol menggantikan hapus: alasan wajib, izin wajib, stok
 * dikembalikan sesuai kebijakan, dan jejaknya terekam audit.
 */
$confirmVoid = function ($id) {
    $this->voidingTransactionId = $id;
    $this->voidReason = '';
    $this->showVoidModal = true;
};

$voidTransaction = function () {
    $transaction = Transaction::find($this->voidingTransactionId);

    if (! $transaction) {
        Flux::toast(variant: 'error', text: __('Transaksi tidak ditemukan.'));

        return;
    }

    $actor = auth()->user();

    try {
        $result = app(VoidSale::class)->handle([
            'transaction_id' => $transaction->id,
            'user_id' => $actor?->id,
            'tenant_id' => $actor?->tenant_id,
            'reason' => $this->voidReason,
        ], $actor);
    } catch (ActionForbidden|VoidNotAllowed $e) {
        Flux::toast(variant: 'error', text: $e->getMessage());

        return;
    }

    $this->voidingTransactionId = null;
    $this->voidReason = '';
    $this->showVoidModal = false;

    Flux::toast(
        variant: 'success',
        text: $result['already_void']
            ? __('Transaksi sudah dibatalkan sebelumnya.')
            : __('Transaksi dibatalkan.'),
    );
};

/**
 * Label & warna metode pembayaran (FR-05).
 *
 * Diletakkan sebagai closure (metode komponen) — bukan array biasa — karena
 * Volt hanya mengompilasi closure/`state()` dari skrip halaman; variabel array
 * biasa akan hilang sehingga tampilan diam-diam jatuh ke nilai mentah.
 * Sumber label tunggal adalah `PaymentMethod`; nilai di luar katalog
 * (data impor lama) tetap ditampilkan apa adanya.
 */
$methodLabel = function (string $method): string {
    return \App\Enums\PaymentMethod::labelFor($method);
};

$methodColor = function (string $method): string {
    return match (\App\Enums\PaymentMethod::tryFrom($method)) {
        \App\Enums\PaymentMethod::Cash => 'green',
        \App\Enums\PaymentMethod::Transfer => 'blue',
        \App\Enums\PaymentMethod::DebitCard => 'purple',
        \App\Enums\PaymentMethod::CreditCard => 'orange',
        \App\Enums\PaymentMethod::QrisStatic => 'teal',
        default => 'gray',
    };
};

?>

<x-layouts::app :title="__('Transactions')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Transactions') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="xl">{{ __('Transactions') }}</flux:heading>
                    <flux:subheading>{{ __('Manage sales transactions.') }}</flux:subheading>
                </div>
                <flux:button variant="primary" icon="plus" href="{{ route('transactions.create') }}" >
                    {{ __('New Transaction') }}
                </flux:button>
            </div>

            <flux:input size="md" wire:model.live="search" type="search"
                placeholder="{{ __('Search by invoice or customer...') }}" />

            <div
                class="relative h-full flex-1 overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700 p-6">

                <flux:table :paginate="$this->transactions">
                    <flux:table.columns>
                        <flux:table.column sortable :sorted="$sortBy === 'id'" :direction="$sortDirection"
                            wire:click="sort('id')">
                            {{ __('Invoice') }}
                        </flux:table.column>

                        <flux:table.column>
                            {{ __('Customer') }}
                        </flux:table.column>

                        <flux:table.column>
                            {{ __('Items') }}
                        </flux:table.column>

                        <flux:table.column sortable :sorted="$sortBy === 'total_amount'" :direction="$sortDirection"
                            wire:click="sort('total_amount')">
                            {{ __('Total') }}
                        </flux:table.column>

                        <flux:table.column>
                            {{ __('Payment') }}
                        </flux:table.column>

                        <flux:table.column>
                            {{ __('Actions') }}
                        </flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->transactions as $transaction)
                            <flux:table.row :key="$transaction->id">
                                <flux:table.cell>
                                    <div class="flex flex-col">
                                        <span
                                            @class(['font-medium', 'line-through text-zinc-400' => $transaction->isVoided()])>{{ $transaction->invoice_number }}</span>
                                        <span
                                            class="text-xs text-zinc-500">{{ $transaction->created_at->format('d M Y, H:i') }}</span>
                                    </div>
                                </flux:table.cell>

                                <flux:table.cell>
                                    {{ $transaction->customer_name ?: '-' }}
                                </flux:table.cell>

                                <flux:table.cell>
                                    <flux:badge size="sm" inset="top bottom">
                                        {{ $transaction->items_count }} {{ __('item') }}
                                    </flux:badge>
                                </flux:table.cell>

                                <flux:table.cell variant="strong">
                                    <span
                                        @class(['line-through text-zinc-400' => $transaction->isVoided()])>{{ Number::currency($transaction->total_amount, 'IDR', 'id') }}</span>
                                </flux:table.cell>

                                <flux:table.cell>
                                    @if ($transaction->isVoided())
                                        <flux:badge size="sm" color="red" inset="top bottom">
                                            {{ __('Dibatalkan') }}
                                        </flux:badge>
                                    @else
                                        <flux:badge size="sm"
                                            :color="$this->methodColor($transaction->payment_method)" inset="top bottom">
                                            {{ $this->methodLabel($transaction->payment_method) }}
                                        </flux:badge>
                                    @endif
                                </flux:table.cell>

                                <flux:table.cell>
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal"
                                            inset="top bottom" />
                                        <flux:menu>
                                            <flux:menu.item icon="eye" wire:click="viewDetail({{ $transaction->id }})">
                                                {{ __('View') }}
                                            </flux:menu.item>
                                            @unless ($transaction->isVoided())
                                                <flux:menu.item icon="pencil-square"
                                                    href="{{ route('transactions.edit', ['transaction' => $transaction->id]) }}">
                                                    {{ __('Edit') }}
                                                </flux:menu.item>
                                            @endunless
                                            <flux:menu.item icon="printer"
                                                href="/transactions/{{ $transaction->id }}/receipt"
                                                target="_blank">
                                                {{ __('Print') }}
                                            </flux:menu.item>
                                            @unless ($transaction->isVoided())
                                                @can('transaction.void')
                                                    <flux:menu.separator />
                                                    <flux:menu.item icon="x-circle" variant="danger"
                                                        wire:click="confirmVoid({{ $transaction->id }})">
                                                        {{ __('Batalkan') }}
                                                    </flux:menu.item>
                                                @endcan
                                            @endunless
                                        </flux:menu>
                                    </flux:dropdown>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
            {{-- Detail Modal --}}
            <flux:modal wire:model.self="showDetailModal" class="max-w-2xl">
                @if ($this->viewTransaction)
                    <div class="space-y-6">
                        <div class="flex items-start justify-between">
                            <div>
                                <flux:heading size="lg">{{ $this->viewTransaction->invoice_number }}</flux:heading>
                                <flux:subheading>
                                    {{ $this->viewTransaction->created_at->format('d M Y, H:i') }}
                                    &middot;
                                    {{ $this->viewTransaction->customer_name ?: '-' }}
                                </flux:subheading>
                            </div>
                            <flux:badge size="sm" inset="top bottom">
                                {{ $this->methodLabel($this->viewTransaction->payment_method) }}
                            </flux:badge>
                        </div>

                        <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800">
                                        <th class="px-3 py-2 text-left font-medium">{{ __('Product') }}</th>
                                        <th class="px-3 py-2 text-right font-medium">{{ __('Price') }}</th>
                                        <th class="px-3 py-2 text-center font-medium">{{ __('Qty') }}</th>
                                        <th class="px-3 py-2 text-right font-medium">{{ __('Subtotal') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($this->viewTransaction->items as $item)
                                        <tr class="border-b border-zinc-100 last:border-b-0 dark:border-zinc-800">
                                            <td class="px-3 py-2">{{ $item->product?->name ?? '-' }}</td>
                                            <td class="px-3 py-2 text-right">
                                                {{ Number::currency($item->unit_price, 'IDR', 'id') }}</td>
                                            <td class="px-3 py-2 text-center">{{ $item->quantity }}</td>
                                            <td class="px-3 py-2 text-right font-medium">
                                                {{ Number::currency($item->subtotal, 'IDR', 'id') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="border-t bg-zinc-50 font-semibold dark:border-zinc-700 dark:bg-zinc-800">
                                        <td colspan="3" class="px-3 py-2 text-right">{{ __('Total') }}</td>
                                        <td class="px-3 py-2 text-right">
                                            {{ Number::currency($this->viewTransaction->total_amount, 'IDR', 'id') }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                            <div>
                                <span class="text-zinc-500">{{ __('Paid') }}</span>
                                <p class="font-medium">
                                    {{ Number::currency($this->viewTransaction->paid_amount, 'IDR', 'id') }}</p>
                            </div>
                            <div>
                                <span class="text-zinc-500">{{ __('Change') }}</span>
                                <p class="font-medium">
                                    {{ Number::currency($this->viewTransaction->change_amount, 'IDR', 'id') }}</p>
                            </div>
                            <div>
                                <span class="text-zinc-500">{{ __('Items') }}</span>
                                <p class="font-medium">{{ $this->viewTransaction->items->count() }}
                                    {{ __('item') }}</p>
                            </div>
                        </div>

                        @if ($this->viewTransaction->isVoided())
                            <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm dark:border-red-900 dark:bg-red-950/40">
                                <p class="font-medium text-red-700 dark:text-red-300">
                                    {{ __('Dibatalkan') }}
                                    @if ($this->viewTransaction->voided_at)
                                        · {{ $this->viewTransaction->voided_at->format('d M Y, H:i') }}
                                    @endif
                                </p>
                                <p class="mt-1 text-red-700 dark:text-red-300">
                                    {{ $this->viewTransaction->void_reason ?: '-' }}
                                </p>
                            </div>
                        @endif

                        @if ($this->viewTransaction->notes)
                            <div>
                                <span class="text-sm text-zinc-500">{{ __('Notes') }}</span>
                                <p class="mt-1 text-sm">{{ $this->viewTransaction->notes }}</p>
                            </div>
                        @endif

                        <div class="flex justify-end gap-2">
                            @unless ($this->viewTransaction->isVoided())
                                <flux:button variant="filled"
                                    href="{{ route('transactions.edit', ['transaction' => $this->viewTransaction->id]) }}">
                                    {{ __('Edit Transaction') }}
                                </flux:button>
                            @endunless
                            <flux:button variant="filled"
                                href="/transactions/{{ $this->viewTransaction->id }}/receipt"
                                target="_blank"
                                >
                                {{ __('Print Receipt') }}
                            </flux:button>
                            <flux:modal.close>
                                <flux:button variant="filled">{{ __('Close') }}</flux:button>
                            </flux:modal.close>
                        </div>
                    </div>
                @endif
            </flux:modal>

            {{-- Void Confirmation Modal (§17.4) --}}
            <flux:modal wire:model.self="showVoidModal" class="max-w-lg">
                <form wire:submit="voidTransaction" class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Batalkan Transaksi') }}</flux:heading>
                        <flux:subheading>
                            {{ __('Transaksi tidak dihapus, tetapi ditandai batal. Stok dikembalikan sesuai kebijakan dan tindakan ini tercatat di jejak audit bersama alasannya.') }}
                        </flux:subheading>
                    </div>

                    <flux:textarea wire:model="voidReason" :label="__('Alasan pembatalan')"
                        placeholder="{{ __('Contoh: salah input item, pelanggan membatalkan pesanan...') }}"
                        rows="3" required />

                    <flux:callout variant="warning" icon="exclamation-triangle">
                        <flux:callout.heading>{{ __('Perhatian') }}</flux:callout.heading>
                        <flux:callout.text>
                            {{ __('Transaksi yang sudah dibatalkan tidak dapat diaktifkan kembali. Koreksi dilakukan dengan membuat transaksi baru.') }}
                        </flux:callout.text>
                    </flux:callout>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="danger" type="submit" :disabled="trim($voidReason) === ''">
                            {{ __('Batalkan Transaksi') }}
                        </flux:button>
                    </div>
                </form>
            </flux:modal>
        </div>
    @endvolt
</x-layouts::app>
