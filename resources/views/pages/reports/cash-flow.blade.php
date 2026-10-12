<?php

use App\Actions\Reports\GenerateCashFlowReport;
use Illuminate\Support\Number;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\updated;

name('reports.cash-flow');

middleware('auth');
middleware('verified');

state([
    'from_date' => now()->startOfMonth()->format('Y-m-d'),
    'to_date' => now()->format('Y-m-d'),
])->url();

$options = fn () => [
    'from_date' => $this->from_date ?: null,
    'to_date' => $this->to_date ?: null,
    'tenant_id' => auth()->user()->tenant_id,
];

updated(['from_date', 'to_date'], function () {
    $this->dispatch('cashflow-update', chart: $this->chartData);
});

$rows = computed(fn () => app(GenerateCashFlowReport::class)->handle($this->options()));

$totals = computed(fn () => app(GenerateCashFlowReport::class)->getTotals($this->options()));

$summaryByCategory = computed(fn () => app(GenerateCashFlowReport::class)->getSummaryByCategory($this->options()));

$chartData = computed(fn () => app(GenerateCashFlowReport::class)->getDailyChartData($this->options()));

$categoryLabels = fn ($category) => match ($category) {
    'penjualan_tunai' => __('Penjualan Tunai'),
    'pelunasan_piutang' => __('Pelunasan Piutang'),
    'retur_pembelian' => __('Refund Retur Pembelian'),
    'pembelian' => __('Pembelian'),
    'pembayaran_supplier' => __('Pembayaran Supplier'),
    'retur_penjualan' => __('Retur Penjualan'),
    default => ucwords(str_replace('_', ' ', $category)),
};

$methodLabel = fn ($method) => \App\Enums\PaymentMethod::labelFor($method);

?>

<x-layouts::app :title="__('Cash Flow Report')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="/reports">{{ __('Reports') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Cash Flow') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="xl">{{ __('Cash Flow Report') }}</flux:heading>
                    <flux:subheading>{{ __('Pemasukan dan pengeluaran kas per hari dalam periode tertentu') }}</flux:subheading>
                </div>
                <flux:button variant="ghost" icon="arrow-left" href="/reports">
                    {{ __('Back to Reports') }}
                </flux:button>
            </div>

            <flux:card class="!p-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('From') }}</flux:label>
                        <flux:input type="date" wire:model.live="from_date" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('To') }}</flux:label>
                        <flux:input type="date" wire:model.live="to_date" />
                    </flux:field>
                </div>
            </flux:card>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <flux:card class="!p-5">
                    <div class="text-sm text-zinc-500">{{ __('Total Masuk') }}</div>
                    <div class="mt-1 text-2xl font-semibold text-emerald-600">{{ Number::currency($this->totals['total_in'], 'IDR', 'id') }}</div>
                </flux:card>
                <flux:card class="!p-5">
                    <div class="text-sm text-zinc-500">{{ __('Total Keluar') }}</div>
                    <div class="mt-1 text-2xl font-semibold text-red-600">{{ Number::currency($this->totals['total_out'], 'IDR', 'id') }}</div>
                </flux:card>
                <flux:card class="!p-5">
                    <div class="text-sm text-zinc-500">{{ __('Arus Kas Bersih') }}</div>
                    <div class="mt-1 text-2xl font-semibold {{ $this->totals['net'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                        {{ Number::currency($this->totals['net'], 'IDR', 'id') }}
                    </div>
                </flux:card>
            </div>

            <flux:card class="!p-6">
                <flux:heading size="lg" class="mb-6">{{ __('Arus Kas Harian') }}</flux:heading>
                <div wire:ignore id="cashflowChart" class="h-[300px] w-full"></div>
            </flux:card>

            <flux:card class="!p-5">
                <flux:heading size="lg" class="mb-4">{{ __('Ringkasan per Kategori') }}</flux:heading>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                    @forelse ($this->summaryByCategory as $category => $summary)
                        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                            <div class="text-sm font-medium">{{ $this->categoryLabels($category) }}</div>
                            <div class="mt-2 space-y-1 text-sm">
                                <div class="flex justify-between"><span class="text-zinc-500">{{ __('Masuk') }}</span><span class="text-emerald-600">{{ Number::currency($summary['in'], 'IDR', 'id') }}</span></div>
                                <div class="flex justify-between"><span class="text-zinc-500">{{ __('Keluar') }}</span><span class="text-red-600">{{ Number::currency($summary['out'], 'IDR', 'id') }}</span></div>
                                <div class="flex justify-between border-t border-zinc-200 pt-1 dark:border-zinc-700"><span>{{ __('Net') }}</span><span class="font-medium">{{ Number::currency($summary['net'], 'IDR', 'id') }}</span></div>
                            </div>
                            <div class="mt-2 text-xs text-zinc-400">{{ $summary['count'] }} {{ __('transaksi') }}</div>
                        </div>
                    @empty
                        <p class="text-sm text-zinc-500">{{ __('Belum ada transaksi kas pada periode ini.') }}</p>
                    @endforelse
                </div>
            </flux:card>

            <flux:card class="!p-5">
                <flux:heading size="lg" class="mb-4">{{ __('Detail Arus Kas') }}</flux:heading>
                <div class="overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Tanggal') }}</flux:table.column>
                            <flux:table.column>{{ __('Kategori') }}</flux:table.column>
                            <flux:table.column>{{ __('Keterangan') }}</flux:table.column>
                            <flux:table.column>{{ __('Metode') }}</flux:table.column>
                            <flux:table.column>{{ __('Jumlah') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->rows as $row)
                                <flux:table.row :key="'cf-' . $row['reference']">
                                    <flux:table.cell class="text-xs whitespace-nowrap text-zinc-500">{{ $row['date_formatted'] }}</flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge :color="$row['type'] === 'in' ? 'green' : 'red'" size="sm" inset="top bottom">
                                            {{ $this->categoryLabels($row['category']) }}
                                        </flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell class="text-sm">{{ $row['description'] }}</flux:table.cell>
                                    <flux:table.cell class="text-xs text-zinc-500">{{ $this->methodLabel($row['payment_method']) }}</flux:table.cell>
                                    <flux:table.cell class="font-medium {{ $row['type'] === 'in' ? 'text-emerald-600' : 'text-red-600' }}">
                                        {{ $row['type'] === 'in' ? '+' : '-' }} {{ Number::currency($row['amount_minor'], 'IDR', 'id') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="5" class="text-center text-zinc-500 py-6">
                                        {{ __('Tidak ada transaksi kas pada periode ini.') }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>
            </flux:card>
        </div>

        @push('scripts')
            <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
            <script>
                document.addEventListener('livewire:initialized', () => {
                    const isDark = document.documentElement.classList.contains('dark');
                    const themeColor = isDark ? '#f4f4f5' : '#18181b';
                    const gridColor = isDark ? '#3f3f46' : '#e4e4e7';

                    const cashflowChart = new ApexCharts(document.querySelector("#cashflowChart"), {
                        series: [{
                            name: '{{ __("Masuk") }}',
                            color: '#10b981',
                            data: @js($this->chartData['series_in'])
                        }, {
                            name: '{{ __("Keluar") }}',
                            color: '#ef4444',
                            data: @js($this->chartData['series_out'])
                        }, {
                            name: '{{ __("Net") }}',
                            color: themeColor,
                            data: @js($this->chartData['series_net'])
                        }],
                        chart: {
                            type: 'bar',
                            height: 300,
                            toolbar: { show: false },
                            background: 'transparent',
                            fontFamily: 'Inter, sans-serif',
                            stacked: false
                        },
                        plotOptions: {
                            bar: { horizontal: false, columnWidth: '55%', borderRadius: 4 }
                        },
                        xaxis: {
                            categories: @js($this->chartData['labels']),
                            labels: { style: { colors: '#71717a' } }
                        },
                        yaxis: {
                            labels: {
                                style: { colors: '#71717a' },
                                formatter: (v) => Number(v).toLocaleString('id-ID')
                            }
                        },
                        grid: { borderColor: gridColor, strokeDashArray: 4 },
                        tooltip: {
                            theme: isDark ? 'dark' : 'light',
                            y: { formatter: (v) => Number(v).toLocaleString('id-ID') }
                        }
                    });
                    cashflowChart.render();

                    Livewire.on('cashflow-update', (event) => {
                        cashflowChart.updateOptions({
                            xaxis: { categories: event.chart.labels },
                            series: [
                                { data: event.chart.series_in },
                                { data: event.chart.series_out },
                                { data: event.chart.series_net }
                            ]
                        });
                    });
                });
            </script>
        @endpush
    @endvolt
</x-layouts::app>