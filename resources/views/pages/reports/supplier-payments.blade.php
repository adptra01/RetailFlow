<?php

use App\Actions\Reports\GenerateSupplierPaymentReport;
use App\Models\Supplier;
use Illuminate\Support\Number;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;

name('reports.supplier-payments');

middleware('auth');
middleware('verified');

state([
    'from_date' => now()->startOfMonth()->format('Y-m-d'),
    'to_date' => now()->format('Y-m-d'),
    'supplier_id' => '',
])->url();

$suppliers = computed(fn () => Supplier::query()->orderBy('name')->pluck('name', 'id'));

$options = fn () => [
    'from_date' => $this->from_date ?: null,
    'to_date' => $this->to_date ?: null,
    'supplier_id' => $this->supplier_id ?: null,
    'tenant_id' => auth()->user()->tenant_id,
];

$rows = computed(fn () => app(GenerateSupplierPaymentReport::class)->handle($this->options()));

$outstanding = computed(fn () => app(GenerateSupplierPaymentReport::class)->getSupplierOutstanding($this->options()));

$totals = computed(fn () => app(GenerateSupplierPaymentReport::class)->getTotals($this->options()));

$methodLabel = fn ($method) => \App\Enums\PaymentMethod::labelFor($method);

?>

<x-layouts::app :title="__('Supplier Payments Report')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="/reports">{{ __('Reports') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Supplier Payments') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="xl">{{ __('Supplier Payments Report') }}</flux:heading>
                    <flux:subheading>{{ __('Riwayat pembayaran ke supplier dan hutang outstanding') }}</flux:subheading>
                </div>
                <flux:button variant="ghost" icon="arrow-left" href="/reports">
                    {{ __('Back to Reports') }}
                </flux:button>
            </div>

            <flux:card class="!p-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <flux:field>
                        <flux:label>{{ __('From') }}</flux:label>
                        <flux:input type="date" wire:model.live="from_date" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('To') }}</flux:label>
                        <flux:input type="date" wire:model.live="to_date" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Supplier') }}</flux:label>
                        <flux:select wire:model.live="supplier_id" :label="__('Supplier')">
                            <flux:select.option value="">{{ __('Semua supplier') }}</flux:select.option>
                            @foreach ($this->suppliers as $id => $name)
                                <flux:select.option :value="$id">{{ $name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                </div>
            </flux:card>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <flux:card class="!p-5">
                    <div class="text-sm text-zinc-500">{{ __('Total Dibayar') }}</div>
                    <div class="mt-1 text-xl font-semibold text-emerald-600">{{ $this->totals['total_paid_formatted'] }}</div>
                    <div class="text-xs text-zinc-400">{{ $this->rows->count() }} {{ __('pembayaran') }}</div>
                </flux:card>
                <flux:card class="!p-5">
                    <div class="text-sm text-zinc-500">{{ __('Hutang Outstanding') }}</div>
                    <div class="mt-1 text-xl font-semibold text-red-600">{{ $this->totals['total_outstanding_formatted'] }}</div>
                    <div class="text-xs text-zinc-400">{{ $this->totals['supplier_count'] }} {{ __('supplier') }}</div>
                </flux:card>
                <flux:card class="!p-5">
                    <div class="text-sm text-zinc-500">{{ __('Nilai PO Belum Lunas') }}</div>
                    <div class="mt-1 text-xl font-semibold">{{ $this->totals['po_pending_formatted'] }}</div>
                </flux:card>
            </div>

            <flux:card class="!p-5">
                <flux:heading size="lg" class="mb-4">{{ __('Hutang per Supplier') }}</flux:heading>
                <div class="overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Supplier') }}</flux:table.column>
                            <flux:table.column>{{ __('Hutang') }}</flux:table.column>
                            <flux:table.column>{{ __('PO Aktif') }}</flux:table.column>
                            <flux:table.column>{{ __('Nilai PO') }}</flux:table.column>
                            <flux:table.column>{{ __('Pembayaran Terakhir') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->outstanding as $supplier)
                                <flux:table.row :key="'outs-' . $supplier['supplier_id']">
                                    <flux:table.cell class="font-medium">{{ $supplier['supplier_name'] }}</flux:table.cell>
                                    <flux:table.cell class="font-medium text-red-600">{{ $supplier['balance_formatted'] }}</flux:table.cell>
                                    <flux:table.cell>{{ $supplier['po_pending_count'] }}</flux:table.cell>
                                    <flux:table.cell>{{ $supplier['po_total_formatted'] }}</flux:table.cell>
                                    <flux:table.cell class="text-xs text-zinc-500">
                                        {{ $supplier['last_payment_date'] ? $supplier['last_payment_date'] . ' · ' . $supplier['last_payment_formatted'] : __('-') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="5" class="text-center text-zinc-500 py-6">
                                        {{ __('Tidak ada supplier dengan hutang outstanding.') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>
            </flux:card>

            <flux:card class="!p-5">
                <flux:heading size="lg" class="mb-4">{{ __('Riwayat Pembayaran') }}</flux:heading>
                <div class="overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Tanggal') }}</flux:table.column>
                            <flux:table.column>{{ __('Supplier') }}</flux:table.column>
                            <flux:table.column>{{ __('Referensi PO') }}</flux:table.column>
                            <flux:table.column>{{ __('Metode') }}</flux:table.column>
                            <flux:table.column>{{ __('Jumlah') }}</flux:table.column>
                            <flux:table.column>{{ __('Dicatat oleh') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->rows as $payment)
                                <flux:table.row :key="'sp-' . $payment['id']">
                                    <flux:table.cell class="text-xs whitespace-nowrap text-zinc-500">{{ $payment['payment_date_formatted'] }}</flux:table.cell>
                                    <flux:table.cell class="font-medium">{{ $payment['supplier_name'] }}</flux:table.cell>
                                    <flux:table.cell class="font-mono text-xs">{{ $payment['purchase_order_number'] }}</flux:table.cell>
                                    <flux:table.cell class="text-xs text-zinc-500">{{ $this->methodLabel($payment['payment_method']) }}</flux:table.cell>
                                    <flux:table.cell class="font-medium">{{ $payment['amount_formatted'] }}</flux:table.cell>
                                    <flux:table.cell class="text-xs text-zinc-500">{{ $payment['user_name'] }}</flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="6" class="text-center text-zinc-500 py-6">
                                        {{ __('Belum ada pembayaran ke supplier pada periode ini.') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>
            </flux:card>
        </div>
    @endvolt
</x-layouts::app>