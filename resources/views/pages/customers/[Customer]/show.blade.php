<?php

use App\Actions\Receivables\AddReceivableNote;
use App\Actions\Receivables\RecordReceivablePayment;
use App\Actions\Receivables\ReverseReceivablePayment;
use App\Enums\CollectionChannel;
use App\Enums\Permission;
use App\Exceptions\Receivables\CollectionNoteNotAllowed;
use App\Exceptions\Receivables\PaymentReversalNotAllowed;
use App\Exceptions\Sales\InvalidPaymentAmount;
use App\Exceptions\Sales\ReceivableClosed;
use App\Models\Customer;
use App\Models\Receivable;
use App\Models\ReceivableNote;
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
    'showReversalModal' => false,
    'reversalPaymentId' => null,
    'reversalReason' => '',
    'showNoteModal' => false,
    'noteReceivableId' => null,
    'noteChannel' => '',
    'noteText' => '',
    'noteContactedAt' => '',
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

$selectedReversalPayment = computed(function () {
    if (! $this->reversalPaymentId) {
        return null;
    }

    return ReceivablePayment::with('receivable.transaction')->find($this->reversalPaymentId);
});

$collectionNotes = computed(function () {
    return ReceivableNote::with(['receivable.transaction', 'author'])
        ->whereHas('receivable', fn ($q) => $q->where('customer_id', $this->customer->id))
        ->orderByDesc('contacted_at')
        ->orderByDesc('id')
        ->limit(50)
        ->get();
});

$noteTargetReceivable = computed(function () {
    if (! $this->noteReceivableId) {
        return null;
    }

    return Receivable::with('transaction')->find($this->noteReceivableId);
});

$channelOptions = computed(fn () => collect(CollectionChannel::cases())
    ->map(fn (CollectionChannel $channel) => ['value' => $channel->value, 'label' => $channel->label()])
    ->all());

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

/** Buka modal koreksi untuk satu pembayaran piutang (FR-07). */
$openReversalModal = function (int $paymentId) {
    $payment = ReceivablePayment::with('receivable')->findOrFail($paymentId);

    abort_unless($payment->receivable?->customer_id === $this->customer->id, 404);

    // Pembayaran yang sudah dikoreksi tidak perlu dikoreksi lagi.
    if ($payment->isReversed()) {
        Flux::toast(variant: 'error', text: __('Pembayaran ini sudah dikoreksi.'));

        return;
    }

    $this->reversalPaymentId = $payment->id;
    $this->reversalReason = '';
    $this->showReversalModal = true;
};

/** Koreksi pembayaran piutang lewat prosedur yang diaudit (FR-07). */
$correctPayment = function () {
    $this->validate([
        'reversalPaymentId' => 'required|integer',
        'reversalReason' => 'required|string|min:3|max:500',
    ]);

    $user = auth()->user();

    if ($user === null || ! $user->can(Permission::CorrectReceivablePayment->value)) {
        Flux::toast(variant: 'error', text: __('Anda tidak berizin mengoreksi pembayaran.'));

        return;
    }

    $payment = ReceivablePayment::with('receivable')->findOrFail($this->reversalPaymentId);

    abort_unless($payment->receivable?->customer_id === $this->customer->id, 404);

    try {
        app(ReverseReceivablePayment::class)->handle([
            'payment_id' => $payment->id,
            'user_id' => $user->id,
            'reason' => $this->reversalReason,
        ], $user);
    } catch (PaymentReversalNotAllowed $e) {
        Flux::toast(variant: 'error', text: $e->getMessage());

        return;
    }

    $this->showReversalModal = false;
    $this->reset(['reversalPaymentId', 'reversalReason']);
    Flux::toast(variant: 'success', text: __('Pembayaran piutang dikoreksi.'));
};

/** Buka modal catatan penagihan untuk satu piutang (FR-07). */
$openNoteModal = function (int $receivableId) {
    $receivable = Receivable::findOrFail($receivableId);

    abort_unless($receivable->customer_id === $this->customer->id, 404);

    $this->noteReceivableId = $receivable->id;
    $this->noteChannel = '';
    $this->noteText = '';
    $this->noteContactedAt = now()->format('Y-m-d');
    $this->showNoteModal = true;
};

/** Simpan catatan penagihan (FR-07: append-only + tercatat di audit). */
$saveNote = function () {
    $this->validate([
        'noteReceivableId' => 'required|integer',
        'noteText' => 'required|string|min:2|max:1000',
        'noteChannel' => 'nullable|string|max:32',
        'noteContactedAt' => 'nullable|date',
    ]);

    $receivable = Receivable::findOrFail($this->noteReceivableId);

    abort_unless($receivable->customer_id === $this->customer->id, 404);

    try {
        app(AddReceivableNote::class)->handle([
            'receivable_id' => $receivable->id,
            'note' => $this->noteText,
            'channel' => $this->noteChannel ?: null,
            'contacted_at' => $this->noteContactedAt ?: null,
            'user_id' => auth()->id(),
            'tenant_id' => auth()->user()?->tenant_id,
        ]);
    } catch (CollectionNoteNotAllowed $e) {
        Flux::toast(variant: 'error', text: $e->getMessage());

        return;
    }

    $this->showNoteModal = false;
    $this->reset(['noteReceivableId', 'noteChannel', 'noteText', 'noteContactedAt']);
    Flux::toast(variant: 'success', text: __('Catatan penagihan tersimpan.'));
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
                                                <flux:menu.item icon="chat-bubble-left-right" wire:click="openNoteModal({{ $receivable->id }})">
                                                    {{ __('Add Collection Note') }}
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
                                <th class="p-3 font-medium text-end">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                            @forelse ($this->paymentHistory as $payment)
                                <tr class="{{ $payment->isReversed() ? 'opacity-60' : '' }}">
                                    <td class="p-3 text-zinc-600 dark:text-zinc-400">{{ $payment->created_at->format('d M Y') }}</td>
                                    <td class="p-3 font-medium">{{ $payment->receivable->transaction?->invoice_number ?? 'N/A' }}</td>
                                    <td class="p-3 text-right font-mono {{ $payment->isReversed() ? 'text-zinc-400 line-through' : 'text-green-600' }}">
                                        {{ Number::currency($payment->amount_minor, 'IDR', 'id') }}
                                    </td>
                                    <td class="p-3 text-zinc-600 dark:text-zinc-400 text-xs">{{ $payment->payment_method }}</td>
                                    <td class="p-3 text-zinc-500 max-w-[200px] truncate">{{ $payment->notes ?: '—' }}</td>
                                    <td class="p-3 text-end">
                                        @if ($payment->isReversed())
                                            <flux:badge color="rose" size="sm" inset="top bottom">{{ __('Dikoreksi') }}</flux:badge>
                                        @else
                                            @can('receivable.payment.correct')
                                                <flux:button size="sm" variant="ghost" icon="arrow-uturn-left"
                                                    wire:click="openReversalModal({{ $payment->id }})">
                                                    {{ __('Koreksi') }}
                                                </flux:button>
                                            @endcan
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="p-6 text-center text-zinc-500">{{ __('No payment history.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{ $this->paymentHistory->links() }}
            </div>

            {{-- Collection Notes (FR-07: catatan penagihan) --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <flux:heading size="lg">{{ __('Collection Notes') }}</flux:heading>
                        <flux:subheading>{{ __('Riwayat penagihan piutang pelanggan ini.') }}</flux:subheading>
                    </div>
                </div>

                @if ($this->collectionNotes->isEmpty())
                    <p class="text-sm text-zinc-500">{{ __('Belum ada catatan penagihan.') }}</p>
                @else
                    <ul class="space-y-3">
                        @foreach ($this->collectionNotes as $note)
                            <li class="rounded-lg border border-zinc-100 p-3 dark:border-zinc-700" wire:key="note-{{ $note->id }}">
                                <div class="flex flex-wrap items-center gap-2 text-xs text-zinc-500">
                                    <span>{{ $note->contacted_at?->format('d M Y H:i') ?? $note->created_at->format('d M Y H:i') }}</span>
                                    @if ($note->channel)
                                        @php($channelLabel = \App\Enums\CollectionChannel::tryFrom($note->channel)?->label() ?? $note->channel)
                                        <flux:badge size="sm" color="zinc">{{ $channelLabel }}</flux:badge>
                                    @endif
                                    <span class="font-medium">{{ $note->receivable->transaction?->invoice_number ?? 'N/A' }}</span>
                                    @if ($note->author)
                                        <span>· {{ $note->author->name }}</span>
                                    @endif
                                </div>
                                <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-200">{{ $note->note }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
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

            {{-- Reversal Modal (FR-07: koreksi ber-audit) --}}
            @can('receivable.payment.correct')
            <flux:modal wire:model.self="showReversalModal" class="max-w-lg">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Koreksi Pembayaran') }}</flux:heading>
                        <flux:subheading>{{ __('Pembayaran akan ditandai dikoreksi dan sisa piutang dikembalikan. Jejak audit tetap tersimpan.') }}</flux:subheading>
                    </div>

                    @if ($this->selectedReversalPayment)
                    <div class="rounded-lg border p-4 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50">
                        <div class="flex justify-between">
                            <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Amount:') }}</span>
                            <strong>{{ Number::currency($this->selectedReversalPayment->amount_minor, 'IDR', 'id') }}</strong>
                        </div>
                        <div class="mt-1 flex justify-between">
                            <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Invoice:') }}</span>
                            <span>{{ $this->selectedReversalPayment->receivable?->transaction?->invoice_number ?? 'N/A' }}</span>
                        </div>
                    </div>
                    @endif

                    <flux:textarea wire:model="reversalReason" rows="3"
                        label="{{ __('Alasan Koreksi') }}"
                        placeholder="{{ __('Contoh: pembayaran tercatat ganda') }}" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                        <flux:button variant="danger" wire:click="correctPayment" :disabled="!$reversalReason">
                            {{ __('Koreksi Pembayaran') }}
                        </flux:button>
                    </div>
                </div>
            </flux:modal>
            @endcan

            {{-- Collection Note Modal (FR-07) --}}
            <flux:modal wire:model.self="showNoteModal" class="max-w-lg">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Catatan Penagihan') }}</flux:heading>
                        <flux:subheading>{{ __('Catat aktivitas penagihan untuk piutang ini.') }}</flux:subheading>
                    </div>

                    @if ($this->noteTargetReceivable)
                    <div class="rounded-lg border p-4 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50">
                        <div class="flex justify-between">
                            <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Invoice:') }}</span>
                            <strong>{{ $this->noteTargetReceivable->transaction?->invoice_number ?? 'N/A' }}</strong>
                        </div>
                        <div class="mt-1 flex justify-between">
                            <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Remaining:') }}</span>
                            <span>{{ Number::currency($this->noteTargetReceivable->remaining_minor, 'IDR', 'id') }}</span>
                        </div>
                    </div>
                    @endif

                    <flux:textarea wire:model="noteText" rows="3"
                        label="{{ __('Catatan') }}"
                        placeholder="{{ __('Contoh: dihubungi via WhatsApp, janji bayar minggu depan') }}" />

                    <div class="grid grid-cols-2 gap-3">
                        <flux:select wire:model="noteChannel" label="{{ __('Saluran') }}">
                            <flux:select.option value="">{{ __('—') }}</flux:select.option>
                            @foreach ($this->channelOptions as $option)
                                <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:input wire:model="noteContactedAt" type="date" label="{{ __('Tanggal Dihubungi') }}" />
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                        <flux:button variant="primary" wire:click="saveNote" :disabled="!$noteText">
                            {{ __('Simpan Catatan') }}
                        </flux:button>
                    </div>
                </div>
            </flux:modal>
        </div>
    @endvolt
</x-layouts::app>