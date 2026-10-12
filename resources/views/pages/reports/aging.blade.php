<?php

use App\Actions\Reports\GenerateAgingReceivablesReport;
use Illuminate\Support\Number;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;

name('reports.aging');

middleware('auth');
middleware('verified');

state([
    'as_of_date' => now()->format('Y-m-d'),
])->url();

$options = fn () => [
    'as_of_date' => $this->as_of_date ?: null,
    'tenant_id' => auth()->user()->tenant_id,
];

$rows = computed(fn () => app(GenerateAgingReceivablesReport::class)->handle($this->options()));

$agingSummary = computed(fn () => app(GenerateAgingReceivablesReport::class)->getAgingSummary($this->options()));

$customerAging = computed(fn () => app(GenerateAgingReceivablesReport::class)->getCustomerAging($this->options()));

$bucketColor = fn ($bucket) => match ($bucket) {
    'Current' => 'green',
    '1-30 Days' => 'yellow',
    '31-60 Days' => 'amber',
    '61-90 Days' => 'orange',
    '>90 Days' => 'red',
    default => 'zinc',
};

?>

<x-layouts::app :title="__('Aging Receivables Report')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="/reports">{{ __('Reports') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Aging Receivables') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="xl">{{ __('Aging Receivables Report') }}</flux:heading>
                    <flux:subheading>{{ __('Umur piutang berdasarkan jatuh tempo') }}</flux:subheading>
                </div>
                <flux:button variant="ghost" icon="arrow-left" href="/reports">
                    {{ __('Back to Reports') }}
                </flux:button>
            </div>

            <flux:card class="!p-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Per Tanggal') }}</flux:label>
                        <flux:input type="date" wire:model.live="as_of_date" />
                    </flux:field>
                </div>
            </flux:card>

            <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
                @foreach ($this->agingSummary as $bucket => $summary)
                    <flux:card class="!p-5">
                        <flux:badge :color="$this->bucketColor($bucket)" size="sm" inset="top bottom">{{ __($bucket) }}</flux:badge>
                        <div class="mt-3 text-base font-semibold">{{ $summary['total_formatted'] }}</div>
                        <div class="text-xs text-zinc-500">{{ $summary['count'] }} {{ __('piutang') }}</div>
                    </flux:card>
                @endforeach
            </div>

            <flux:card class="!p-5">
                <flux:heading size="lg" class="mb-4">{{ __('Ringkasan per Pelanggan') }}</flux:heading>
                <div class="overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Pelanggan') }}</flux:table.column>
                            <flux:table.column>{{ __('Current') }}</flux:table.column>
                            <flux:table.column>{{ __('1-30 Hari') }}</flux:table.column>
                            <flux:table.column>{{ __('31-60 Hari') }}</flux:table.column>
                            <flux:table.column>{{ __('61-90 Hari') }}</flux:table.column>
                            <flux:table.column>{{ __('>90 Hari') }}</flux:table.column>
                            <flux:table.column>{{ __('Total') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->customerAging['details'] as $customer)
                                <flux:table.row :key="'aging-c-' . $customer['customer']">
                                    <flux:table.cell class="font-medium">{{ $customer['customer'] }}</flux:table.cell>
                                    @foreach (['Current', '1-30 Days', '31-60 Days', '61-90 Days', '>90 Days'] as $bucket)
                                        <flux:table.cell class="text-xs">
                                            @if ($customer['buckets'][$bucket] > 0)
                                                <span class="{{ $bucket === 'Current' ? '' : 'text-red-600' }}">{{ Number::currency($customer['buckets'][$bucket], 'IDR', 'id') }}</span>
                                            @else
                                                <span class="text-zinc-400">-</span>
                                            @endif
                                        </flux:table.cell>
                                    @endforeach
                                    <flux:table.cell class="font-medium">{{ $customer['total_formatted'] }}</flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="7" class="text-center text-zinc-500 py-6">
                                        {{ __('Tidak ada piutang terbuka.') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>
            </flux:card>

            <flux:card class="!p-5">
                <flux:heading size="lg" class="mb-4">{{ __('Detail Piutang') }}</flux:heading>
                <div class="overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Invoice') }}</flux:table.column>
                            <flux:table.column>{{ __('Pelanggan') }}</flux:table.column>
                            <flux:table.column>{{ __('Jatuh Tempo') }}</flux:table.column>
                            {{-- <flux:table.column>{{ __('Terlambat') }}</flux:table.column> --}}
                            <flux:table.column>{{ __('Sisa Piutang') }}</flux:table.column>
                            <flux:table.column>{{ __('Tingkat Umur') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->rows as $receivable)
                                <flux:table.row :key="'aging-' . $receivable['id']">
                                    <flux:table.cell class="font-mono text-xs">{{ $receivable['invoice_number'] }}</flux:table.cell>
                                    <flux:table.cell>{{ $receivable['customer_name'] }}</flux:table.cell>
                                    <flux:table.cell class="text-xs text-zinc-500">{{ $receivable['due_date'] ?? __('-') }}</flux:table.cell>
                                    {{-- <flux:table.cell class="text-xs">{{ $receivable['days_overdue'] }} hari</flux:table.cell> --}}
                                    <flux:table.cell class="font-medium">{{ $receivable['remaining_formatted'] }}</flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge :color="$this->bucketColor($receivable['aging_bucket'])" size="sm" inset="top bottom">
                                            {{ __($receivable['aging_bucket']) }}
                                        </flux:badge>
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="5" class="text-center text-zinc-500 py-6">
                                        {{ __('Tidak ada piutang terbuka.') }}
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