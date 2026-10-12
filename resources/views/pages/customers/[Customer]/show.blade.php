<?php

use App\Actions\Receivables\RecordReceivablePayment;
use App\Exceptions\Sales\InvalidPaymentAmount;
use App\Exceptions\Sales\ReceivableClosed;
use App\Models\Customer;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\WithPagination;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

name('customers.show');
middleware('auth');
middleware('verified');
uses(WithPagination::class);

state([
    'customerId' => null,
    'showPaymentModal' => false,
    'selectedReceivableId' => null,
    'paymentAmount' => '',
    'paymentMethod' => 'cash',
    'paymentNote' => '',
    'clientRequestId' => null,
]);

// Folio mengikat segmen [Customer] menjadi model Customer dan meneruskannya
// ke mount (pola yang sama dipakai halaman transaksi & struk) — tetapi nilai
// route mentah tetap didukung sebagai fallback agar tidak 404.
mount(function (?Customer $customer = null) {
    if ($customer) {
        $this->customerId = $customer->getKey();
    }
});

// Folio mengikat segmen [Customer] menjadi model Customer, tetapi pola
// request()->route('customer') di halaman edit mengasumsikan id mentah.
// Resolver ini menerima keduanya sehingga halaman tidak 404 apa pun
// bentuk nilai route yang diberikan Folio.
$resolveCustomerId = function () {
    $routeValue = request()->route('customer');

    return $routeValue instanceof Customer ? $routeValue->getKey() : $routeValue;
};

$customer = computed(function () use ($resolveCustomerId) {
    return Customer::with('tenant')->findOrFail($this->customerId ?? $resolveCustomerId());
});

$openReceivables = computed(function () {
    return Receivable::with('transaction')
        ->where('customer_id', $this->customer->id)
        ->where('status', 'open')
        ->where('remaining_minor', '>', 0)
        ->orderBy('due_at')
        ->get();
});

$paymentHistory = computed(function () {
    return ReceivablePayment::with('receivable.transaction')
        ->whereHas('receivable', fn ($q) => $q->where('customer_id', $this->customer->id))
        ->orderByDesc('created_at')
        ->paginate(10);
});

$totalReceivable = computed(function () {
    return (int) Receivable::where('customer_id', $this->customer->id)
        ->where('status', 'open')
        ->sum('remaining_minor');
});

$selectedReceivable = computed(function () {
    if (! $this->selectedReceivableId) {
        return null;
    }

    return Receivable::with('transaction')->find($this->selectedReceivableId);
});

$openPaymentModal = function ($receivableId) {
    $receivable = Receivable::findOrFail($receivableId);

    abort_unless($receivable->customer_id === $this->customer->id, 404);

    $this->selectedReceivableId = $receivable->id;
    // IDR memakai eksponen minor 0: 1 minor == Rp1 (lihat Money + config
    // pos.currency.minor_exponent), jadi nominal rupiah dipakai apa adanya.
    $this->paymentAmount = $receivable->remaining_minor;
    $this->paymentNote = '';
    $this->clientRequestId = (string) Str::uuid();
    $this->showPaymentModal = true;
};

$recordPayment = function () {
    $this->validate([
        'paymentAmount' => 'required|numeric|min:1',
        'selectedReceivableId' => 'required|integer',
        'paymentMethod' => 'required|string|max:32',
        'paymentNote' => 'nullable|string|max:255',
    ]);

    $receivable = Receivable::findOrFail($this->selectedReceivableId);

    abort_unless($receivable->customer_id === $this->customer->id, 404);

    $amountMinor = (int) round((float) $this->paymentAmount);

    if ($amountMinor > $receivable->remaining_minor) {
        Flux::toast(variant: 'error', text: __('Pembayaran melebihi sisa piutang.'));

        return;
    }

    try {
        app(RecordReceivablePayment::class)->handle([
            'receivable_id' => $receivable->id,
            'amount_minor' => $amountMinor,
            'payment_method' => $this->paymentMethod,
            'user_id' => auth()->id(),
            'client_request_id' => $this->clientRequestId ?: (string) Str::uuid(),
            'notes' => $this->paymentNote ?: null,
        ]);
    } catch (InvalidPaymentAmount|ReceivableClosed $e) {
        Flux::toast(variant: 'error', text: $e->getMessage());

        return;
    }

    $this->showPaymentModal = false;
    $this->reset(['selectedReceivableId', 'paymentAmount', 'paymentMethod', 'paymentNote', 'clientRequestId']);
    $this->paymentMethod = 'cash';
    Flux::toast(variant: 'success', text: __('Payment recorded successfully.'));
};

?>

<x-layouts::app :title="__('Customer Detail')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item href="/customers">{{ __('Customers') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ $this->customer->name }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            {{-- Customer Info & Summary --}}
            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:heading size="sm">{{ __('Customer') }}</flux:heading>
                    <p class="mt-1 font-medium text-xl">{{ $this->customer->name }}</p>
                    <p class="text-sm text-zinc-500">{{ $this->customer->phone ?: '—' }}</p>
                    <p class="text-sm text-zinc-500 mt-1">{{ $this->customer->address ?: '—' }}</p>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:heading size="sm">{{ __('Credit Limit') }}</flux:heading>
                    <p class="mt-1 font-medium text-xl">
                        {{ Number::currency($this->customer->credit_limit ?? 0, 'IDR', 'id') }}
                    </p>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:heading size="sm">{{ __('Total Outstanding') }}</flux:heading>
                    <p class="mt-1 font-medium text-xl text-red-600">
                        {{ Number::currency($this->totalReceivable, 'IDR', 'id') }}
                    </p>
                    @if ($this->customer->credit_limit > 0)
                        <p class="text-sm text-zinc-500 mt-1">
                            {{ __('Available') }}:
                            {{ Number::currency(max(0, (float) $this->customer->credit_limit - $this->totalReceivable), 'IDR', 'id') }}
                        </p>
                    @endif
                </div>
            </div>

            {{-- Open Receivables --}}
            @if ($this->openReceivables->isNotEmpty())
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <flux:heading size="lg">{{ __('Outstanding Receivables') }}</flux:heading>
                        <flux:subheading>{{ $this->openReceivables->count() }} {{ __('invoices pending') }}</flux:subheading>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">
                            <tr>
                                <th class="p-3 font-medium">{{ __('Invoice') }}</th>
                                <th class="p-3 font-medium">{{ __('Date') }}</th>
                                <th class="p-3 font-medium">{{ __('Due Date') }}</th>
                                <th class="p-3 font-medium text-right">{{ __('Original') }}</th>
                                <th class="p-3 font-medium text-right">{{ __('Paid') }}</th>
                                <th class="p-3 font-medium text-right text-red-600">{{ __('Remaining') }}</th>
                                <th class="p-3 font-medium">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                            @foreach ($this->openReceivables as $receivable)
                                <tr>
                                    <td class="p-3 font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $receivable->transaction?->invoice_number ?? 'N/A' }}
                                    </td>
                                    <td class="p-3 text-zinc-600 dark:text-zinc-400">
                                        {{ $receivable->created_at?->format('d M Y') }}
                                    </td>
                                    <td class="p-3 text-zinc-600 dark:text-zinc-400">
                                        {{ $receivable->due_at?->format('d M Y') ?? '—' }}
                                        @if ($receivable->due_at && $receivable->due_at->isPast() && $receivable->remaining_minor > 0)
                                            <flux:badge size="xs" color="red" class="ml-1">{{ __('Overdue') }}</flux:badge>
                                        @endif
                                    </td>
                                    <td class="p-3 text-right text-zinc-600 dark:text-zinc-400">
                                        {{ Number::currency($receivable->original_amount_minor, 'IDR', 'id') }}
                                    </td>
                                    <td class="p-3 text-right text-zinc-600 dark:text-zinc-400">
                                        {{ Number::currency($receivable->original_amount_minor - $receivable->remaining_minor, 'IDR', 'id') }}
                                    </td>
                                    <td class="p-3 text-right font-semibold text-red-600 dark:text-red-400">
                                        {{ Number::currency($receivable->remaining_minor, 'IDR', 'id') }}
                                    </td>
                                    <td class="p-3">
                                        <flux:dropdown position="bottom" align="end">
                                            <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" />
                                            <flux:menu>
                                                <flux:menu.item icon="credit-card" wire:click="openPaymentModal({{ $receivable->id }})">
                                                    {{ __('Record Payment') }}
                                                </flux:menu.item>
                                                <flux:menu.item icon="eye" href="/transactions/{{ $receivable->transaction_id }}">
                                                    {{ __('View Transaction') }}
                                                </flux:menu.item>
                                            </flux:menu>
                                        </flux:dropdown>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @else
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800 text-center">
                <flux:icon name="check-circle" variant="micro" class="mx-auto text-green-500 mb-2" />
                <p class="text-zinc-600 dark:text-zinc-400">{{ __('No outstanding receivables.') }}</p>
            </div>
            @endif

            {{-- Payment History --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <flux:heading size="lg">{{ __('Payment History') }}</flux:heading>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">
                            <tr>
                                <th class="p-3 font-medium">{{ __('Date') }}</th>
                                <th class="p-3 font-medium">{{ __('Invoice') }}</th>
                                <th class="p-3 font-medium text-right">{{ __('Amount') }}</th>
                                <th class="p-3 font-medium">{{ __('Method') }}</th>
                                <th class="p-3 font-medium">{{ __('Notes') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                            @forelse ($this->paymentHistory as $payment)
                                <tr>
                                    <td class="p-3 text-zinc-600 dark:text-zinc-400">{{ $payment->created_at->format('d M Y') }}</td>
                                    <td class="p-3 font-medium">{{ $payment->receivable->transaction?->invoice_number ?? 'N/A' }}</td>
                                    <td class="p-3 text-right font-mono text-green-600">
                                        {{ Number::currency($payment->amount_minor, 'IDR', 'id') }}
                                    </td>
                                    <td class="p-3 text-zinc-600 dark:text-zinc-400 text-xs">{{ $payment->payment_method }}</td>
                                    <td class="p-3 text-zinc-500 max-w-[200px] truncate">{{ $payment->notes ?: '—' }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="p-6 text-center text-zinc-500">{{ __('No payment history.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{ $this->paymentHistory->links() }}
            </div>

            {{-- Payment Modal --}}
            <flux:modal wire:model.self="showPaymentModal" class="max-w-lg">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Record Payment') }}</flux:heading>
                        <flux:subheading>{{ __('Record payment for invoice ') . ($this->selectedReceivable?->transaction?->invoice_number ?? '') }}</flux:heading>
                    </div>

                    @if ($this->selectedReceivable)
                    <div class="rounded-lg border p-4 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50">
                        <div class="flex justify-between">
                            <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Remaining:') }}</span>
                            <strong>{{ Number::currency($this->selectedReceivable->remaining_minor, 'IDR', 'id') }}</strong>
                        </div>
                    </div>
                    @endif

                    <flux:input wire:model="paymentAmount" type="number" step="1" min="1"
                        label="{{ __('Amount') }}"
                        placeholder="{{ __('Enter amount...') }}" />

                    <flux:select wire:model="paymentMethod" label="{{ __('Payment Method') }}">
                        <option value="cash">{{ __('Cash') }}</option>
                        <option value="transfer">{{ __('Transfer') }}</option>
                        <option value="qr">{{ __('QRIS') }}</option>
                        <option value="card">{{ __('Card') }}</option>
                    </flux:select>

                    <flux:input wire:model="paymentNote" placeholder="{{ __('Notes...') }}" label="{{ __('Notes') }}" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                        <flux:button variant="primary" wire:click="recordPayment" :disabled="!$paymentAmount || !$selectedReceivableId">
                            {{ __('Save Payment') }}
                        </flux:button>
                    </div>
                </div>
            </flux:modal>
        </div>
    @endvolt
</x-layouts::app>