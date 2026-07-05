<?php

use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Support\Number;
use Illuminate\Support\Carbon;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\updated;

name('reports.index');

middleware('auth');
middleware('verified');

state([
    'from_date' => now()->startOfMonth()->format('Y-m-d'),
    'to_date' => now()->format('Y-m-d'),
])->url();

// Trigger update chart saat tanggal berubah
updated(['from_date', 'to_date'], function () {
    $this->dispatch('charts-update', daily: $this->dailyRevenueData, payment: $this->paymentMethodData, category: $this->categoryData, top: $this->topProductsData);
});

$summary = computed(function () {
    $query = Transaction::whereBetween('created_at', [$this->from_date . ' 00:00:00', $this->to_date . ' 23:59:59']);

    $totalRevenue = (float) $query->sum('total_amount');
    $totalTransactions = $query->count();
    $totalItems = TransactionItem::whereHas('transaction', function ($q) {
        $q->whereBetween('created_at', [$this->from_date . ' 00:00:00', $this->to_date . ' 23:59:59']);
    })->sum('quantity');

    return [
        'total_revenue' => $totalRevenue,
        'total_transactions' => $totalTransactions,
        'average_order' => $totalTransactions > 0 ? round($totalRevenue / $totalTransactions, 2) : 0,
        'total_items_sold' => $totalItems,
    ];
});

$dailyRevenueData = computed(function () {
    $sales = Transaction::whereBetween('created_at', [$this->from_date . ' 00:00:00', $this->to_date . ' 23:59:59'])
        ->selectRaw('DATE(created_at) as date, SUM(total_amount) as revenue')
        ->groupBy('date')
        ->orderBy('date')
        ->get();

    return [
        'labels' => $sales->map(fn($s) => Carbon::parse($s->date)->format('d M'))->toArray(),
        'series' => $sales->map(fn($s) => (float) $s->revenue)->toArray(),
    ];
});

$paymentMethodData = computed(function () {
    $labels_map = [
        'cash' => __('Cash'),
        'transfer' => __('Transfer'),
        'debit_card' => __('Debit Card'),
        'credit_card' => __('Credit Card'),
    ];

    $rows = Transaction::whereBetween('created_at', [$this->from_date . ' 00:00:00', $this->to_date . ' 23:59:59'])
        ->selectRaw('payment_method, SUM(total_amount) as total')
        ->groupBy('payment_method')
        ->get();

    return [
        'labels' => $rows->map(fn($r) => $labels_map[$r->payment_method] ?? $r->payment_method)->toArray(),
        'series' => $rows->map(fn($r) => (float) $r->total)->toArray(),
    ];
});

$topProductsData = computed(function () {
    $rows = TransactionItem::selectRaw('product_id, SUM(subtotal) as revenue')
        ->whereHas('transaction', function ($q) {
            $q->whereBetween('created_at', [$this->from_date . ' 00:00:00', $this->to_date . ' 23:59:59']);
        })
        ->with('product:id,name')
        ->groupBy('product_id')
        ->orderByDesc('revenue')
        ->limit(5)
        ->get();

    return [
        'labels' => $rows->map(fn($r) => $r->product?->name ?? __('Unknown'))->toArray(),
        'series' => $rows->map(fn($r) => (float) $r->revenue)->toArray(),
    ];
});

$categoryData = computed(function () {
    $rows = TransactionItem::selectRaw('products.category_id, SUM(transaction_items.subtotal) as revenue')
        ->join('products', 'transaction_items.product_id', '=', 'products.id')
        ->whereHas('transaction', function ($q) {
            $q->whereBetween('created_at', [$this->from_date . ' 00:00:00', $this->to_date . ' 23:59:59']);
        })
        ->groupBy('products.category_id')
        ->orderByDesc('revenue')
        ->get();

    $categories = Category::pluck('name', 'id');

    return [
        'labels' => $rows->map(fn($r) => $categories[$r->category_id] ?? __('Unknown'))->toArray(),
        'series' => $rows->map(fn($r) => (float) $r->revenue)->toArray(),
    ];
});

?>

<x-layouts::app :title="__('Reports')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Reports') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Reports') }}</flux:heading>
            </div>

            {{-- Date Range Filter --}}
            <div
                class="flex flex-wrap items-end gap-4 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="w-full">
                    <flux:input wire:model.live="from_date" type="date" :label="__('From')" />
                </div>
                <div class="w-full">
                    <flux:input wire:model.live="to_date" type="date" :label="__('To')" />
                </div>
                <flux:spacer />
                <div class="text-sm text-zinc-500">
                    {{ __('Showing data from') }} <span
                        class="font-medium text-zinc-900 dark:text-zinc-100">{{ Carbon::parse($from_date)->format('d M Y') }}</span>
                    {{ __('to') }} <span
                        class="font-medium text-zinc-900 dark:text-zinc-100">{{ Carbon::parse($to_date)->format('d M Y') }}</span>
                </div>
            </div>

            {{-- Summary Cards --}}
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-2">
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-sm text-zinc-500">{{ __('Total Revenue') }}</p>
                    <p class="mt-1 text-2xl font-bold">{{ Number::currency($this->summary['total_revenue'], 'IDR', 'id') }}
                    </p>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-sm text-zinc-500">{{ __('Transactions') }}</p>
                    <p class="mt-1 text-2xl font-bold">{{ Number::format($this->summary['total_transactions']) }}</p>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-sm text-zinc-500">{{ __('Avg Order Value') }}</p>
                    <p class="mt-1 text-2xl font-bold">{{ Number::currency($this->summary['average_order'], 'IDR', 'id') }}
                    </p>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <p class="text-sm text-zinc-500">{{ __('Items Sold') }}</p>
                    <p class="mt-1 text-2xl font-bold">{{ Number::format($this->summary['total_items_sold']) }}</p>
                </div>
            </div>

            {{-- Daily Revenue Chart --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="lg" class="mb-6">{{ __('Daily Revenue') }}</flux:heading>
                <div wire:ignore id="dailyRevenueChart" class="h-[350px] w-full"></div>
            </div>

            {{-- Breakdown Grid --}}
            <div class="grid gap-6 lg:grid-cols-2">
                {{-- Payment Methods --}}
                <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:heading size="lg" class="mb-6">{{ __('Payment Methods') }}</flux:heading>
                    <div wire:ignore id="paymentMethodChart" class="h-[300px] w-full"></div>
                </div>

                {{-- Sales by Category --}}
                <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:heading size="lg" class="mb-6">{{ __('Sales by Category') }}</flux:heading>
                    <div wire:ignore id="categoryChart" class="h-[300px] w-full"></div>
                </div>
            </div>

            {{-- Top Products --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="lg" class="mb-6">{{ __('Top Products by Revenue') }}</flux:heading>
                <div wire:ignore id="topProductsChart" class="h-[350px] w-full"></div>
            </div>
        </div>

        @push('scripts')
            <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
            <script>
                document.addEventListener('livewire:initialized', () => {
                    const isDark = document.documentElement.classList.contains('dark');
                    const themeColor = isDark ? '#f4f4f5' : '#18181b';
                    const gridColor = isDark ? '#3f3f46' : '#e4e4e7';

                    // Area Chart for Daily Revenue
                    const dailyRevenueChart = new ApexCharts(document.querySelector("#dailyRevenueChart"), {
                        series: [{
                        name: '{{ __('Revenue') }}',
                        data: @js($this->dailyRevenueData['series'])
                        }],
                        chart: {
                            type: 'area',
                            height: 350,
                            toolbar: {
                                show: false
                            },
                            background: 'transparent',
                            fontFamily: 'Inter, sans-serif'
                        },
                        stroke: {
                            curve: 'smooth',
                            width: 2,
                            colors: [themeColor]
                        },
                        fill: {
                            type: 'gradient',
                            gradient: {
                                opacityFrom: 0.4,
                                opacityTo: 0
                            }
                        },
                        xaxis: {
                            categories: @js($this->dailyRevenueData['labels']),
                            labels: {
                                style: {
                                    colors: '#71717a'
                                }
                            }
                        },
                        yaxis: {
                            labels: {
                                style: {
                                    colors: '#71717a'
                                },
                                formatter: (v) => Number(v).toLocaleString('id-ID')
                            }
                        },
                        grid: {
                            borderColor: gridColor,
                            strokeDashArray: 4
                        },
                        tooltip: {
                            theme: isDark ? 'dark' : 'light'
                        }
                    });
                    dailyRevenueChart.render();

                    // Donut Chart for Payment Methods
                    const paymentMethodChart = new ApexCharts(document.querySelector("#paymentMethodChart"), {
                        series: @js($this->paymentMethodData['series']),
                        labels: @js($this->paymentMethodData['labels']),
                        chart: {
                            type: 'donut',
                            height: 300,
                            fontFamily: 'Inter, sans-serif'
                        },
                        stroke: {
                            show: false
                        },
                        legend: {
                            position: 'bottom',
                            labels: {
                                colors: '#71717a'
                            }
                        },
                        plotOptions: {
                            pie: {
                                donut: {
                                    size: '75%'
                                }
                            }
                        },
                        tooltip: {
                            theme: isDark ? 'dark' : 'light'
                        }
                    });
                    paymentMethodChart.render();

                    // Donut Chart for Category Breakdown
                    const categoryChart = new ApexCharts(document.querySelector("#categoryChart"), {
                        series: @js($this->categoryData['series']),
                        labels: @js($this->categoryData['labels']),
                        chart: {
                            type: 'donut',
                            height: 300,
                            fontFamily: 'Inter, sans-serif'
                        },
                        stroke: {
                            show: false
                        },
                        legend: {
                            position: 'bottom',
                            labels: {
                                colors: '#71717a'
                            }
                        },
                        plotOptions: {
                            pie: {
                                donut: {
                                    size: '75%'
                                }
                            }
                        },
                        tooltip: {
                            theme: isDark ? 'dark' : 'light'
                        }
                    });
                    categoryChart.render();

                    // Bar Chart for Top Products
                    const topProductsChart = new ApexCharts(document.querySelector("#topProductsChart"), {
                        series: [{
                        name: '{{ __('Revenue') }}',
                        data: @js($this->topProductsData['series'])
                        }],
                        chart: {
                            type: 'bar',
                            height: 350,
                            toolbar: {
                                show: false
                            },
                            fontFamily: 'Inter, sans-serif'
                        },
                        plotOptions: {
                            bar: {
                                horizontal: true,
                                borderRadius: 4,
                                barHeight: '60%'
                            }
                        },
                        colors: [themeColor],
                        xaxis: {
                            categories: @js($this->topProductsData['labels']),
                            labels: {
                                style: {
                                    colors: '#71717a'
                                }
                            }
                        },
                        yaxis: {
                            labels: {
                                style: {
                                    colors: '#71717a'
                                }
                            }
                        },
                        grid: {
                            borderColor: gridColor,
                            strokeDashArray: 4
                        },
                        tooltip: {
                            theme: isDark ? 'dark' : 'light'
                        }
                    });
                    topProductsChart.render();

                    // Handle Updates secara reaktif
                    Livewire.on('charts-update', (event) => {
                        // Di Livewire 3, 'event' langsung berisi object yang kita kirim dari PHP
                        const data = event;

                        dailyRevenueChart.updateOptions({
                            xaxis: {
                                categories: data.daily.labels
                            },
                            series: [{
                                data: data.daily.series
                            }]
                        });

                        paymentMethodChart.updateOptions({
                            labels: data.payment.labels,
                            series: data.payment.series
                        });

                        categoryChart.updateOptions({
                            labels: data.category.labels,
                            series: data.category.series
                        });

                        topProductsChart.updateOptions({
                            xaxis: {
                                categories: data.top.labels
                            },
                            series: [{
                                data: data.top.series
                            }]
                        });
                    });
                });
            </script>
        @endpush
    @endvolt
</x-layouts::app>
