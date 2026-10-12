<?php

use App\Actions\Reports\GenerateMarginHistoryReport;
use Illuminate\Support\Number;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\updated;

name('reports.margin');

middleware('auth');
middleware('verified');

state([
    'from_date' => now()->startOfMonth()->format('Y-m-d'),
    'to_date' => now()->format('Y-m-d'),
    'group_by' => 'day',
])->url();

$options = fn () => [
    'from_date' => $this->from_date ?: null,
    'to_date' => $this->to_date ?: null,
    'group_by' => $this->group_by,
    'tenant_id' => auth()->user()->tenant_id,
];

updated(['from_date', 'to_date', 'group_by'], function () {
    $this->dispatch('margin-update', chart: $this->chartData);
});

$rows = computed(fn () => app(GenerateMarginHistoryReport::class)->handle($this->options()));

$productMargins = computed(fn () => app(GenerateMarginHistoryReport::class)->getProductMargins($this->options()));

$totals = computed(fn () => app(GenerateMarginHistoryReport::class)->getTotals([
    'from_date' => $this->from_date ?: null,
    'to_date' => $this->to_date ?: null,
    'tenant_id' => auth()->user()->tenant_id,
]));

$chartData = computed(fn () => app(GenerateMarginHistoryReport::class)->getChartData($this->options()));

$groupLabels = fn ($group) => match ($group) {
    'day' => __('Per Hari'),
    'week' => __('Per Minggu'),
    'month' => __('Per Bulan'),
    default => $group,
};

?>

<x-layouts::app :title="__('Margin History Report')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="/reports">{{ __('Reports') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Margin History') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="xl">{{ __('Margin History Report') }}</flux:heading>
                    <flux:subheading>{{ __('Pendapatan, HPP, dan margin kotor per periode') }}</flux:subheading>
                </div>
                <flux:button variant="ghost" icon="arrow-left" href="/reports">
                    {{ __('Back to Reports') }}
                </flux:button>
            </div>

            <flux:card class="!p-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <flux:field>
                        <flux:label>{{ __('From') }}</flux:label>
                        <flux:input type="date" wire:model.live="from_date" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('To') }}</flux:label>
                        <flux:input type="date" wire:model.live="to_date" />
                    </flux:field>
                    <flux:field class="md:col-span-2">
                        <flux:label>{{ __('Kelompokkan') }}</flux:label>
                        <flux:select wire:model.live="group_by" :label="__('Kelompokkan')">
                            <flux:select.option value="day">{{ __('Per Hari') }}</flux:select.option>
                            <flux:select.option value="week">{{ __('Per Minggu') }}</flux:select.option>
                            <flux:select.option value="month">{{ __('Per Bulan') }}</flux:select.option>
                        </flux:select>
                    </flux:field>
                </div>
            </flux:card>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <flux:card class="!p-5">
                    <div class="text-sm text-zinc-500">{{ __('Pendapatan') }}</div>
                    <div class="mt-1 text-xl font-semibold">{{ Number::currency($this->totals['total_revenue'], 'IDR', 'id') }}</div>
                </flux:card>
                <flux:card class="!p-5">
                    <div class="text-sm text-zinc-500">{{ __('HPP / COGS') }}</div>
                    <div class="mt-1 text-xl font-semibold text-zinc-600 dark:text-zinc-300">{{ Number::currency($this->totals['total_cogs'], 'IDR', 'id') }}</div>
                </flux:card>
                <flux:card class="!p-5">
                    <div class="text-sm text-zinc-500">{{ __('Margin Kotor') }}</div>
                    <div class="mt-1 text-xl font-semibold text-emerald-600">{{ Number::currency($this->totals['total_margin'], 'IDR', 'id') }}</div>
                </flux:card>
                <flux:card class="!p-5">
                    <div class="text-sm text-zinc-500">{{ __('Rata-rata Margin') }}</div>
                    <div class="mt-1 text-xl font-semibold">{{ $this->totals['avg_margin_pct'] }}%</div>
                </flux:card>
            </div>

            <flux:card class="!p-6">
                <flux:heading size="lg" class="mb-6">{{ __('Tren Margin') }}</flux:heading>
                <div wire:ignore id="marginChart" class="h-[300px] w-full"></div>
            </flux:card>

            <flux:card class="!p-5">
                <flux:heading size="lg" class="mb-4">{{ __('Margin per Produk') }}</flux:heading>
                <div class="overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Produk') }}</flux:table.column>
                            <flux:table.column>{{ __('Terjual') }}</flux:table.column>
                            <flux:table.column>{{ __('Pendapatan') }}</flux:table.column>
                            <flux:table.column>{{ __('HPP') }}</flux:table.column>
                            <flux:table.column>{{ __('Margin') }}</flux:table.column>
                            <flux:table.column>{{ __('%') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->productMargins as $product)
                                <flux:table.row :key="'margin-p-' . $product['product_id']">
                                    <flux:table.cell>
                                        <div class="font-medium">{{ $product['product_name'] }}</div>
                                        <div class="text-xs text-zinc-500">{{ $product['product_sku'] }}</div>
                                    </flux:table.cell>
                                    <flux:table.cell>{{ Number::format($product['qty_sold']) }}</flux:table.cell>
                                    <flux:table.cell>{{ Number::currency($product['revenue'], 'IDR', 'id') }}</flux:table.cell>
                                    <flux:table.cell class="text-zinc-500">{{ Number::currency($product['cogs'], 'IDR', 'id') }}</flux:table.cell>
                                    <flux:table.cell class="font-medium {{ $product['margin'] >= 0 ? 'text-emerald-600' : 'text-red-600' }}">
                                        {{ Number::currency($product['margin'], 'IDR', 'id') }}
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge :color="$product['margin_pct'] >= 0 ? 'green' : 'red'" size="sm" inset="top bottom">
                                            {{ $product['margin_pct'] }}%
                                        </flux:badge>
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="6" class="text-center text-zinc-500 py-6">
                                        {{ __('Belum ada penjualan pada periode ini.') }}
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

                    const marginChart = new ApexCharts(document.querySelector("#marginChart"), {
                        series: [{
                            name: '{{ __("Pendapatan") }}',
                            data: @js($this->chartData['series_revenue'])
                        }, {
                            name: '{{ __("HPP") }}',
                            data: @js($this->chartData['series_cogs'])
                        }, {
                            name: '{{ __("Margin") }}',
                            data: @js($this->chartData['series_margin'])
                        }],
                        chart: {
                            type: 'line',
                            height: 300,
                            toolbar: { show: false },
                            background: 'transparent',
                            fontFamily: 'Inter, sans-serif'
                        },
                        stroke: { curve: 'smooth', width: 2 },
                        colors: [themeColor, '#71717a', '#10b981'],
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
                    marginChart.render();

                    Livewire.on('margin-update', (event) => {
                        marginChart.updateOptions({
                            xaxis: { categories: event.chart.labels },
                            series: [
                                { data: event.chart.series_revenue },
                                { data: event.chart.series_cogs },
                                { data: event.chart.series_margin }
                            ]
                        });
                    });
                });
            </script>
        @endpush
    @endvolt
</x-layouts::app>