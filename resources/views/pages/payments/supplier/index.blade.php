<?php

use App\Models\Supplier;
use App\Models\SupplierPayment;
use Flux\Flux;
use Livewire\WithPagination;
use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

name('payments.supplier.index');
middleware('auth');
middleware('verified');
uses(WithPagination::class);

state([
    'search' => '',
    'showCreateModal' => false,
    'supplierSearch' => '',
    'selectedSupplier' => null,
    'amount' => '',
    'paymentMethod' => 'transfer',
    'paymentNote' => '',
]);

$payments = computed(function () {
    return SupplierPayment::with('supplier', 'user')
        ->whereHas('supplier', fn ($q) => $q->where('name', 'like', '%' . $this->search . '%'))
        ->orderByDesc('created_at')
        ->paginate(20);
});

$searchSupplier = function () {
    if (empty($this->supplierSearch)) {
        $this->selectedSupplier = null;
        return;
    }
    $this->selectedSupplier = Supplier::where('name', 'like', '%' . $this->supplierSearch . '%')->first();
};

$savePayment = function () {
    $this->validate([
        'amount' => 'required|numeric|min:0',
        'selectedSupplier' => 'required',
    ]);

    if (!$this->selectedSupplier) return;

    $payment = SupplierPayment::create([
        'supplier_id' => $this->selectedSupplier->id,
        'amount' => $this->amount,
        'payment_date' => now()->toDateString(),
        'payment_method' => $this->paymentMethod,
        'notes' => $this->paymentNote,
        'user_id' => auth()->id(),
    ]);

    $this->selectedSupplier->decrement('balance', $this->amount);

    $this->showCreateModal = false;
    $this->reset(['supplierSearch', 'selectedSupplier', 'amount', 'paymentMethod', 'paymentNote']);
    Flux::toast(variant: 'success', text: __('Payment recorded.'));
};
?>

<x-layouts::app :title="__('Hutang Supplier')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Hutang Supplier') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="xl">{{ __('Hutang Supplier') }}</flux:heading>
                    <flux:subheading>{{ __('Supplier payment history') }}</flux:subheading>
                </div>
                <flux:button variant="primary" icon="plus" wire:click="$set('showCreateModal', true)">
                    {{ __('Record Payment') }}
                </flux:button>
            </div>

            <flux:input size="md" wire:model.live="search" type="search" placeholder="{{ __('Search by supplier name...') }}" />

            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <flux:table :paginate="$this->payments">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Date') }}</flux:table.column>
                        <flux:table.column>{{ __('Supplier') }}</flux:table.column>
                        <flux:table.column>{{ __('Amount') }}</flux:table.column>
                        <flux:table.column>{{ __('Method') }}</flux:table.column>
                        <flux:table.column>{{ __('Notes') }}</flux:table.column>
                        <flux:table.column>{{ __('User') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->payments as $p)
                            <flux:table.row :key="$p->id">
                                <flux:table.cell class="text-xs text-zinc-500">{{ $p->payment_date->format('d M Y') }}</flux:table.cell>
                                <flux:table.cell class="font-medium">{{ $p->supplier?->name }}</flux:table.cell>
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
                        <flux:heading size="lg">{{ __('Record Supplier Payment') }}</flux:heading>
                        <flux:subheading>{{ __('Search and select supplier, then enter amount.') }}</flux:subheading>
                    </div>

                    <flux:input wire:model.live.debounce="supplierSearch" type="search"
                        placeholder="{{ __('Search supplier...') }}" />

                    @if ($this->selectedSupplier)
                        <div class="rounded-lg border p-3 text-sm dark:border-zinc-700">
                            <strong>{{ $this->selectedSupplier->name }}</strong>
                            <span class="ml-2 text-xs text-zinc-500">
                                {{ __('Balance:') }} Rp {{ number_format($this->selectedSupplier->balance, 0, ',', '.') }}
                            </span>
                        </div>
                    @endif

                    <flux:input wire:model="amount" type="number" step="0.01" placeholder="{{ __('Amount...') }}" />

                    <flux:select wire:model="paymentMethod" label="{{ __('Payment Method') }}">
                        <option value="transfer">{{ __('Transfer') }}</option>
                        <option value="cash">{{ __('Cash') }}</option>
                    </flux:select>

                    <flux:input wire:model="paymentNote" placeholder="{{ __('Notes...') }}" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                        <flux:button variant="primary" wire:click="savePayment" :disabled="!$amount || !$selectedSupplier">
                            {{ __('Save Payment') }}
                        </flux:button>
                    </div>
                </div>
            </flux:modal>
        </div>
    @endvolt
</x-layouts::app>
