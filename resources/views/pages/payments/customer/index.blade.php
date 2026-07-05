<?php

use App\Models\Customer;
use App\Models\CustomerPayment;
use Flux\Flux;
use Livewire\WithPagination;
use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

name('payments.customer.index');
middleware('auth');
middleware('verified');
uses(WithPagination::class);

state([
    'search' => '',
    'showCreateModal' => false,
    'customerSearch' => '',
    'selectedCustomer' => null,
    'amount' => '',
    'paymentMethod' => 'cash',
    'paymentNote' => '',
]);

$payments = computed(function () {
    return CustomerPayment::with('customer', 'user')
        ->whereHas('customer', fn ($q) => $q->where('name', 'like', '%' . $this->search . '%'))
        ->orderByDesc('created_at')
        ->paginate(20);
});

$searchCustomer = function () {
    if (empty($this->customerSearch)) {
        $this->selectedCustomer = null;
        return;
    }
    $this->selectedCustomer = Customer::where('name', 'like', '%' . $this->customerSearch . '%')->first();
};

$savePayment = function () {
    $this->validate([
        'amount' => 'required|numeric|min:0',
        'selectedCustomer' => 'required',
    ]);

    if (!$this->selectedCustomer) return;

    $payment = CustomerPayment::create([
        'customer_id' => $this->selectedCustomer->id,
        'amount' => $this->amount,
        'payment_date' => now()->toDateString(),
        'payment_method' => $this->paymentMethod,
        'notes' => $this->paymentNote,
        'user_id' => auth()->id(),
    ]);

    $this->selectedCustomer->decrement('balance', $this->amount);

    $this->showCreateModal = false;
    $this->reset(['customerSearch', 'selectedCustomer', 'amount', 'paymentMethod', 'paymentNote']);
    Flux::toast(variant: 'success', text: __('Payment recorded.'));
};
?>

<x-layouts::app :title="__('Piutang Customer')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Piutang Customer') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="xl">{{ __('Piutang Customer') }}</flux:heading>
                    <flux:subheading>{{ __('Customer payment history') }}</flux:subheading>
                </div>
                <flux:button variant="primary" icon="plus" wire:click="$set('showCreateModal', true)">
                    {{ __('Record Payment') }}
                </flux:button>
            </div>

            <flux:input size="md" wire:model.live="search" type="search" placeholder="{{ __('Search by customer name...') }}" />

            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <flux:table :paginate="$this->payments">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Date') }}</flux:table.column>
                        <flux:table.column>{{ __('Customer') }}</flux:table.column>
                        <flux:table.column>{{ __('Amount') }}</flux:table.column>
                        <flux:table.column>{{ __('Method') }}</flux:table.column>
                        <flux:table.column>{{ __('Notes') }}</flux:table.column>
                        <flux:table.column>{{ __('User') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->payments as $p)
                            <flux:table.row :key="$p->id">
                                <flux:table.cell class="text-xs text-zinc-500">{{ $p->payment_date->format('d M Y') }}</flux:table.cell>
                                <flux:table.cell class="font-medium">{{ $p->customer?->name }}</flux:table.cell>
                                <flux:table.cell class="font-mono">(Rp {{ number_format($p->amount, 0, ',', '.') }})</flux:table.cell>
                                <flux:table.cell class="text-xs">{{ $p->payment_method }}</flux:table.cell>
                                <flux:table.cell class="max-w-[200px] truncate text-xs text-zinc-500">{{ $p->notes ?: '-' }}</flux:table.cell>
                                <flux:table.cell class="text-xs text-zinc-500">{{ $p->user?->name }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>

            {{-- Create Payment Modal --}}
            <flux:modal wire:model.self="showCreateModal" class="max-w-lg">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Record Customer Payment') }}</flux:heading>
                        <flux:subheading>{{ __('Search and select customer, then enter amount.') }}</flux:subheading>
                    </div>

                    <flux:input wire:model.live.debounce="customerSearch" type="search"
                        placeholder="{{ __('Search customer...') }}" />

                    @if ($this->selectedCustomer)
                        <div class="rounded-lg border p-3 text-sm dark:border-zinc-700">
                            <strong>{{ $this->selectedCustomer->name }}</strong>
                            <span class="ml-2 text-xs text-zinc-500">
                                {{ __('Balance:') }} Rp {{ number_format($this->selectedCustomer->balance, 0, ',', '.') }}
                            </span>
                        </div>
                    @endif

                    <flux:input wire:model="amount" type="number" step="0.01" placeholder="{{ __('Amount...') }}" />

                    <flux:select wire:model="paymentMethod" label="{{ __('Payment Method') }}">
                        <option value="cash">{{ __('Cash') }}</option>
                        <option value="transfer">{{ __('Transfer') }}</option>
                    </flux:select>

                    <flux:input wire:model="paymentNote" placeholder="{{ __('Notes...') }}" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                        <flux:button variant="primary" wire:click="savePayment" :disabled="!$amount || !$selectedCustomer">
                            {{ __('Save Payment') }}
                        </flux:button>
                    </div>
                </div>
            </flux:modal>
        </div>
    @endvolt
</x-layouts::app>
