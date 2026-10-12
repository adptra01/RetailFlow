<?php

use App\Actions\Sales\CreateSalesReturn;
use App\Exceptions\Auth\ActionForbidden;
use App\Exceptions\Sales\ReturnNotAllowed;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\Transaction;
use Flux\Flux;
use Livewire\WithPagination;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

name('returns.sales.index');
middleware('auth');
middleware('verified');
uses(WithPagination::class);

/**
 * Retur penjualan (FR-08 "Retur penjualan dengan alasan", §17.4).
 *
 * Halaman ini sengaja tipis: seluruh aturan retur (alasan wajib, izin
 * `transaction.return`, batas jumlah, kebijakan stok, koreksi piutang/refund,
 * idempotensi) ditegakkan `CreateSalesReturn`. UI tidak menulis stok atau
 * angka uang sendiri — kalau menulis sendiri, aturan domain akan bocor.
 */
state([
    'search' => '',
    'showCreateModal' => false,
    'txSearch' => '',
    'selectedTransactionId' => null,
    'returnItems' => [],
    'returnReason' => '',
    'restoreStock' => true,
    'client_request_id' => null,
]);

$returns = computed(function () {
    return SalesReturn::with('customer', 'user')
        ->where(function ($q) {
            $q->where('return_number', 'like', '%'.$this->search.'%')
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', '%'.$this->search.'%'))
                ->orWhereHas('transaction', fn ($t) => $t->where('invoice_number', 'like', '%'.$this->search.'%'));
        })
        ->orderByDesc('created_at')
        ->paginate(20);
});

$selectedTransaction = computed(function () {
    if (! $this->selectedTransactionId) {
        return null;
    }

    return Transaction::with('items.product', 'customer')->find($this->selectedTransactionId);
});

/**
 * Sisa yang masih dapat diretur per baris penjualan — dihitung dari retur
 * sebelumnya supaya UI tidak menawarkan jumlah yang pasti ditolak domain.
 */
$searchTransaction = function () {
    $this->reset('selectedTransactionId', 'returnItems', 'returnReason');
    $this->client_request_id = null;

    if (empty($this->txSearch)) {
        return;
    }

    // §17.4: transaksi yang sudah dibatalkan tidak dapat diretur.
    $tx = Transaction::with('items.product', 'items.productUnit')
        ->where('invoice_number', $this->txSearch)
        ->where('payment_status', '!=', Transaction::PAYMENT_STATUS_VOID)
        ->first();

    if (! $tx) {
        return;
    }

    $alreadyReturned = SalesReturnItem::query()
        ->whereHas('salesReturn', fn ($q) => $q->where('transaction_id', $tx->id))
        ->selectRaw('transaction_item_id, SUM(qty) as returned_qty')
        ->groupBy('transaction_item_id')
        ->pluck('returned_qty', 'transaction_item_id');

    $this->selectedTransactionId = $tx->id;
    $this->returnItems = $tx->items->map(function ($item) use ($alreadyReturned) {
        $sold = (int) $item->quantity;
        $returned = (int) ($alreadyReturned[$item->id] ?? 0);

        return [
            'transaction_item_id' => $item->id,
            'product_id' => $item->product_id,
            'name' => ($item->product?->name ?? '-').($item->unit_name ? " ({$item->unit_name})" : ''),
            'max_qty' => max(0, $sold - $returned),
            'sold_qty' => $sold,
            'returned_qty' => $returned,
            // Nilai efektif per unit menurut nota (setelah diskon baris).
            'unit_value_minor' => $sold > 0 ? (int) round($item->subtotal_minor / $sold) : 0,
            'qty' => 0,
            'reason' => '',
        ];
    })->toArray();
};

$updateQty = function ($index, $value) {
    if (isset($this->returnItems[$index])) {
        $this->returnItems[$index]['qty'] = max(0, min((int) $value, $this->returnItems[$index]['max_qty']));
    }
};

$selectedItems = computed(fn () => collect($this->returnItems)->filter(fn ($i) => ($i['qty'] ?? 0) > 0));

$totalMinor = computed(fn () => (int) $this->selectedItems->sum(fn ($i) => $i['unit_value_minor'] * $i['qty']));

$expectedRefundMinor = computed(function () {
    $total = $this->totalMinor;
    $outstanding = (int) ($this->selectedTransaction?->receivable?->remaining_minor ?? 0);

    return max(0, $total - min($total, $outstanding));
});

$saveReturn = function () {
    if (! $this->selectedTransaction || $this->selectedItems->isEmpty()) {
        return;
    }

    $actor = auth()->user();

    $items = $this->selectedItems
        ->map(fn ($item) => [
            'transaction_item_id' => $item['transaction_item_id'],
            'quantity' => $item['qty'],
            'reason' => $item['reason'] ?: null,
        ])
        ->values()
        ->all();

    try {
        $return = app(CreateSalesReturn::class)->handle([
            'transaction_id' => $this->selectedTransaction->id,
            'user_id' => $actor?->id,
            'tenant_id' => $actor?->tenant_id,
            'reason' => $this->returnReason,
            'items' => $items,
            'restore_stock' => $this->restoreStock,
            'client_request_id' => $this->client_request_id,
        ], $actor);
    } catch (ActionForbidden|ReturnNotAllowed $e) {
        Flux::toast(variant: 'error', text: $e->getMessage());

        return;
    }

    Flux::toast(
        variant: 'success',
        text: __('Retur :number tersimpan — refund :refund.', [
            'number' => $return->return_number,
            'refund' => Number::currency($return->refund_minor, 'IDR', 'id'),
        ]),
    );

    $this->showCreateModal = false;
    $this->reset('selectedTransactionId', 'returnItems', 'returnReason', 'txSearch', 'client_request_id');
    $this->restoreStock = true;
};

?>

<x-layouts::app :title="__('Retur Penjualan')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="{{ route('dashboard') }}">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Retur Penjualan') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="xl">{{ __('Retur Penjualan') }}</flux:heading>
                    <flux:subheading>{{ __('Pengembalian barang oleh pelanggan, beserta koreksi pembayarannya') }}</flux:subheading>
                </div>
                @can('transaction.return')
                    <flux:button variant="primary" icon="plus" wire:click="$set('showCreateModal', true)">
                        {{ __('Retur Baru') }}
                    </flux:button>
                @endcan
            </div>

            <flux:input size="md" wire:model.live="search" type="search"
                placeholder="{{ __('Cari nomor retur, pelanggan, atau nomor nota...') }}" />

            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <flux:table :paginate="$this->returns">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Retur #') }}</flux:table.column>
                        <flux:table.column>{{ __('Tanggal') }}</flux:table.column>
                        <flux:table.column>{{ __('Nota') }}</flux:table.column>
                        <flux:table.column>{{ __('Pelanggan') }}</flux:table.column>
                        <flux:table.column>{{ __('Nilai Retur') }}</flux:table.column>
                        <flux:table.column>{{ __('Koreksi Piutang') }}</flux:table.column>
                        <flux:table.column>{{ __('Refund') }}</flux:table.column>
                        <flux:table.column>{{ __('Alasan') }}</flux:table.column>
                        <flux:table.column>{{ __('Kasir') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($this->returns as $r)
                            <flux:table.row :key="$r->id">
                                <flux:table.cell class="font-mono text-xs font-medium">{{ $r->return_number }}</flux:table.cell>
                                <flux:table.cell class="text-xs text-zinc-500">{{ $r->created_at?->format('d M H:i') }}</flux:table.cell>
                                <flux:table.cell class="font-mono text-xs">{{ $r->transaction?->invoice_number ?? '-' }}</flux:table.cell>
                                <flux:table.cell class="font-medium">{{ $r->customer?->name ?? __('Walk-in') }}</flux:table.cell>
                                <flux:table.cell class="font-mono">{{ Number::currency($r->total_minor, 'IDR', 'id') }}</flux:table.cell>
                                <flux:table.cell class="font-mono text-xs">{{ Number::currency($r->receivable_adjustment_minor, 'IDR', 'id') }}</flux:table.cell>
                                <flux:table.cell class="font-mono text-xs">{{ Number::currency($r->refund_minor, 'IDR', 'id') }}</flux:table.cell>
                                <flux:table.cell class="max-w-[200px] truncate text-xs text-zinc-500" :title="$r->reason">{{ $r->reason ?: '-' }}</flux:table.cell>
                                <flux:table.cell class="text-xs text-zinc-500">{{ $r->user?->name }}</flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="9" class="py-6 text-center text-sm text-zinc-500">
                                    {{ __('Belum ada retur penjualan.') }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>

            {{-- Modal retur baru --}}
            <flux:modal wire:model.self="showCreateModal" class="max-w-3xl">
                <form wire:submit="saveReturn" class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Retur Penjualan Baru') }}</flux:heading>
                        <flux:subheading>{{ __('Cari nota berdasarkan nomor invoice.') }}</flux:subheading>
                    </div>

                    <flux:input wire:model.live.debounce.400ms="txSearch" type="search"
                        placeholder="{{ __('Nomor invoice...') }}" />

                    @if ($this->selectedTransaction)
                        <div class="rounded-lg border p-3 text-sm dark:border-zinc-700">
                            <strong>{{ __('Nota') }}:</strong>
                            #{{ $this->selectedTransaction->invoice_number }}
                            — {{ $this->selectedTransaction->customer_name ?? __('Walk-in') }}
                            — {{ Number::currency($this->selectedTransaction->total_minor, 'IDR', 'id') }}
                            @if ($this->selectedTransaction->isCredit())
                                <div class="text-xs text-zinc-500 mt-1">
                                    {{ __('Sisa piutang') }}:
                                    {{ Number::currency($this->selectedTransaction->receivable?->remaining_minor ?? 0, 'IDR', 'id') }}
                                </div>
                            @endif
                        </div>

                        <div class="overflow-x-auto rounded-lg border dark:border-zinc-700">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b bg-zinc-50 text-left text-xs font-medium dark:border-zinc-700 dark:bg-zinc-800">
                                        <th class="px-3 py-2">{{ __('Produk') }}</th>
                                        <th class="px-3 py-2 text-center">{{ __('Terjual') }}</th>
                                        <th class="px-3 py-2 text-center">{{ __('Sudah Retur') }}</th>
                                        <th class="px-3 py-2 text-center">{{ __('Maks') }}</th>
                                        <th class="px-3 py-2 text-center">{{ __('Jumlah Retur') }}</th>
                                        <th class="px-3 py-2 text-right">{{ __('Nilai/unit') }}</th>
                                        <th class="px-3 py-2 text-right">{{ __('Subtotal') }}</th>
                                        <th class="px-3 py-2">{{ __('Alasan Baris') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($this->returnItems as $index => $item)
                                        <tr class="border-b dark:border-zinc-800">
                                            <td class="px-3 py-2 font-medium">{{ $item['name'] }}</td>
                                            <td class="px-3 py-2 text-center font-mono text-xs">{{ $item['sold_qty'] }}</td>
                                            <td class="px-3 py-2 text-center font-mono text-xs">{{ $item['returned_qty'] }}</td>
                                            <td class="px-3 py-2 text-center font-mono text-xs">{{ $item['max_qty'] }}</td>
                                            <td class="px-3 py-2 text-center">
                                                <input type="number" min="0" max="{{ $item['max_qty'] }}"
                                                    value="{{ $item['qty'] }}"
                                                    @disabled($item['max_qty'] === 0)
                                                    wire:change="updateQty({{ $index }}, $event.target.value)"
                                                    class="w-20 rounded border border-zinc-300 px-2 py-1 text-center text-sm disabled:opacity-50 dark:border-zinc-600 dark:bg-zinc-700">
                                            </td>
                                            <td class="px-3 py-2 text-right font-mono">{{ Number::currency($item['unit_value_minor'], 'IDR', 'id') }}</td>
                                            <td class="px-3 py-2 text-right font-mono">{{ Number::currency($item['unit_value_minor'] * $item['qty'], 'IDR', 'id') }}</td>
                                            <td class="px-3 py-2">
                                                <input type="text" wire:model="returnItems.{{ $index }}.reason"
                                                    placeholder="{{ __('Alasan...') }}"
                                                    class="w-full rounded border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-600 dark:bg-zinc-700">
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="grid grid-cols-2 gap-4 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                            <div>
                                <span class="text-zinc-500 text-sm">{{ __('Nilai retur') }}</span>
                                <div class="font-mono text-lg">{{ Number::currency($this->totalMinor, 'IDR', 'id') }}</div>
                            </div>
                            <div>
                                <span class="text-zinc-500 text-sm">{{ __('Perkiraan refund kas') }}</span>
                                <div class="font-mono text-lg">{{ Number::currency($this->expectedRefundMinor, 'IDR', 'id') }}</div>
                            </div>
                        </div>

                        <flux:textarea wire:model="returnReason"
                            label="{{ __('Alasan retur') }}"
                            placeholder="{{ __('Alasan retur (wajib)...') }}"
                            description="{{ __('§17.4: retur memerlukan alasan dan izin, dan koreksinya diaudit.') }}" />

                        <flux:switch wire:model="restoreStock"
                            label="{{ __('Kembalikan barang ke stok') }}"
                            description="{{ __('Sesuai kebijakan retur; bila barang rusak, matikan agar stok tidak bertambah.') }}" />
                    @elseif ($this->txSearch)
                        <p class="text-sm text-red-500">{{ __('Nota tidak ditemukan (atau sudah dibatalkan).') }}</p>
                    @endif

                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button variant="filled">{{ __('Batal') }}</flux:button></flux:modal.close>
                        <flux:button variant="primary" type="submit"
                            :disabled="empty($this->selectedTransaction) || $this->selectedItems->isEmpty()">
                            {{ __('Simpan Retur') }}
                        </flux:button>
                    </div>
                </form>
            </flux:modal>
        </div>
    @endvolt
</x-layouts::app>
