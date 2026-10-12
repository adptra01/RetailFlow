<?php

use App\Actions\Reports\GeneratePurchaseVsSalesReport;
use Illuminate\Support\Number;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\updated;

name('reports.purchase-vs-sales');

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
    $this->dispatch('pvs-update', chart: $this->chartData);
});

$rows = computed(fn () => app(GeneratePurchaseVsSalesReport::class)->handle($this->options()));

$statusSummary = computed(fn () => app(GeneratePurchaseVsSalesReport::class)->getStatusSummary($this->options()));

$chartData = computed(fn () => app(GeneratePurchaseVsSalesReport::class)->getChartData($this->options(), 10));

$statusColor = fn ($status) => match ($status) {
    'Healthy' => 'green',
    'Overstock' => 'amber',
    'Understock' => 'orange',
    'Dead Stock' => 'red',
    'Stock Out Risk' => 'yellow',
    default => 'zinc',
};

$statusLabel = fn ($status) => match ($status) {
    'Healthy' => __('Sehat'),
    'Overstock' => __('Stok Berlebih'),
    'Understock' => __('Stok Menipis'),
    'Dead Stock' => __('Tidak Laku'),
    'Stock Out Risk' => __('Risiko Kosong'),
    'No Activity' => __('Tidak Ada Aktivitas'),
    default => $status,
};

?>

<x-layouts::app :title="__('Purchase vs Sales Report')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="/reports">{{ __('Reports') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Purchase vs Sales') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="xl">{{ __('Purchase vs Sales Report') }}</flux:heading>
                    <flux:subheading>{{ __('Perbandingan pembelian vs penjualan per produk untuk analisis stok') }}</flux:subheading>
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

            <div class="grid grid-cols-2 gap-4 md:grid-cols-5">
                @foreach ($this->statusSummary as $status => $summary)
                    <flux:card class="!p-5">
                        <flux:badge :color="$this->statusColor($status)" size="sm" inset="top bottom">{{ $this->statusLabel($status) }}</flux:badge>
                        <div class="mt-3 text-2xl font-semibold">{{ $summary['count'] }}</div>
                        <div class="text-xs text-zinc-500">{{ __('produk · selisih qty ') . $summary['total_qty_diff'] }}</div>
                    </flux:card>
                @endforeach
            </div>

            <flux:card class="!p-6">
                <flux:heading size="lg" class="mb-6">{{ __('Pendapatan vs Biaya Pembelian (Top 10)') }}</flux:heading>
                <div wire:ignore id="pvsChart" class="h-[300px] w-full"></div>
            </flux:card>

            <flux:card class="!p-5">
                <flux:heading size="lg" class="mb-4">{{ __('Detail per Produk') }}</flux:heading>
                <div class="overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Produk') }}</flux:table.column>
                            <flux:table.column>{{ __('Terjual') }}</flux:table.column>
                            <flux:table.column>{{ __('Dibeli') }}</flux:table.column>
                            <flux:table.column>{{ __('Selisih Qty') }}</flux:table.column>
                            <flux:table.column>{{ __('Pendapatan') }}</flux:table.column>
                            <flux:table.column>{{ __('Margin') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->rows as $row)
                                <flux:table.row :key="'pvs-' . $row['product_id']">
                                    <flux:table.cell>
                                        <div class="font-medium">{{ $row['product_name'] }}</div>
                                        <div class="text-xs text-zinc-500">{{ $row['product_sku'] }}</div>
                                    </flux:table.cell>
                                    <flux:table.cell>{{ Number::format($row['sales_qty']) }}</flux:table.cell>
                                    <flux:table.cell>{{ Number::format($row['purchase_qty']) }}</flux:table.cell>
                                    <flux:table.cell>
                                        @if ($row['qty_diff'] > 0)
                                            <span class="text-amber-600">+{{ $row['qty_diff'] }}</span>
                                        @elseif ($row['qty_diff'] < 0)
                                            <span class="text-red-600">{{ $row['qty_diff'] }}</span>
                                        @else
                                            <span class="text-zinc-400">0</span>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>{{ Number::currency($row['sales_revenue'], 'IDR', 'id') }}</flux:table.cell>
                                    <flux:table.cell>
                                        <span class="{{ $row['sales_margin'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                                            {{ Number::currency($row['sales_margin'], 'IDR', 'id') }}
                                        </span>
                                        <span class="text-xs text-zinc-500">({{ $row['sales_margin_pct'] }}%)</span>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge :color="$this->statusColor($row['stock_status'])" size="sm" inset="top bottom">
                                            {{ $this->statusLabel($row['stock_status']) }}
                                        </flux:badge>
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="7" class="text-center text-zinc-500 py-6">
                                        {{ __('Tidak ada data penjualan/pembelian pada periode ini.') }}
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

                    const pvsChart = new ApexCharts(document.querySelector("#pvsChart"), {
                        series: [{
                            name: '{{ __("Pendapatan") }}',
                            data: @js($this->chartData['series_revenue'])
                        }, {
                            name: '{{ __("Biaya Pembelian") }}',
                            data: @js($this->chartData['series_purchase_cost'])
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
                            bar: { horizontal: true, borderRadius: 4, barHeight: '55%' }
                        },
                        colors: [themeColor, '#a1a1aa'],
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
                    pvsChart.render();

                    Livewire.on('pvs-update', (event) => {
                        pvsChart.updateOptions({
                            xaxis: { categories: event.chart.labels },
                            series: [
                                { data: event.chart.series_revenue },
                                { data: event.chart.series_purchase_cost }
                            ]
                        });
                    });
                });
            </script>
        @endpush
    @endvolt
</x-layouts::app>