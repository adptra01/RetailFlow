<?php

use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

use function Livewire\Volt\computed;

$revenueToday = computed(function () {
    return Transaction::whereDate('created_at', today())->sum('total_amount');
});

$transactionsToday = computed(function () {
    return Transaction::whereDate('created_at', today())->count();
});

$lowStockCount = computed(function () {
    return Product::where('is_unlimited_stock', false)->where('stock', '>', 0)
        ->where(function ($q) { $q->where('stock', '<', \DB::raw('min_stock'))->orWhere('stock', '<', 5); })
        ->count();
});

$salesTrendData = computed(function () {
    $days = collect();
    for ($i = 6; $i >= 0; $i--) {
        $date = today()->subDays($i);
        $days->put($date->format('Y-m-d'), [
            'label' => $date->format('d M'),
            'total' => 0
        ]);
    }

    $sales = Transaction::selectRaw('DATE(created_at) as date, SUM(total_amount) as total')
        ->whereDate('created_at', '>=', today()->subDays(6))
        ->groupBy('date')
        ->get();

    foreach ($sales as $sale) {
        if ($days->has($sale->date)) {
            $days->put($sale->date, [
                'label' => Carbon::parse($sale->date)->format('d M'),
                'total' => (float) $sale->total
            ]);
        }
    }

    return [
        'labels' => $days->pluck('label')->toArray(),
        'series' => $days->pluck('total')->toArray(),
    ];
});

$recentTransactions = computed(function () {
    return Transaction::latest()->limit(5)->get();
});

$topSellingProducts = computed(function () {
    return TransactionItem::selectRaw('product_id, SUM(quantity) as total_qty')
        ->groupBy('product_id')
        ->orderByDesc('total_qty')
        ->limit(5)
        ->with('product')
        ->get();
});

?>

<x-layouts::app :title="__('Dashboard')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            {{-- Header --}}
            <div>
                <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>
                <flux:subheading>{{ __('Welcome back, :name.', ['name' => auth()->user()->name]) }}</flux:subheading>
            </div>

            {{-- KPI Cards --}}
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                {{-- Revenue Today --}}
                <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-900/20 dark:text-emerald-400">
                            <flux:icon name="banknotes" variant="micro" />
                        </div>
                        <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __("Today's Revenue") }}</p>
                    </div>
                    <p class="mt-4 text-2xl font-bold tracking-tight">
                        {{ Number::currency($this->revenueToday, 'IDR', 'id') }}
                    </p>
                </div>

                {{-- Transactions Today --}}
                <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-900/20 dark:text-blue-400">
                            <flux:icon name="shopping-cart" variant="micro" />
                        </div>
                        <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __("Today's Transactions") }}</p>
                    </div>
                    <p class="mt-4 text-2xl font-bold tracking-tight">
                        {{ Number::format($this->transactionsToday) }}
                    </p>
                </div>

                {{-- Low Stock Alerts --}}
                <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="flex size-10 items-center justify-center rounded-lg bg-rose-50 text-rose-600 dark:bg-rose-900/20 dark:text-rose-400">
                                <flux:icon name="exclamation-triangle" variant="micro" />
                            </div>
                            <p class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __("Low Stock Alerts") }}</p>
                        </div>
                        @if($this->lowStockCount > 0)
                            <flux:link href="{{ route('products.index') }}" class="text-xs font-semibold text-rose-600 dark:text-rose-400">
                                {{ __('View All') }}
                            </flux:link>
                        @endif
                    </div>
                    <p class="mt-4 text-2xl font-bold tracking-tight {{ $this->lowStockCount > 0 ? 'text-rose-600' : '' }}">
                        {{ Number::format($this->lowStockCount) }}
                    </p>
                </div>
            </div>

            {{-- Sales Trend Chart --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="mb-6 flex items-center justify-between">
                    <flux:heading size="lg">{{ __('Sales Trend (Last 7 Days)') }}</flux:heading>
                </div>

                <div wire:ignore id="salesTrendChart" class="h-[300px] w-full"></div>
            </div>

            {{-- Bottom Grid --}}
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                {{-- Recent Transactions --}}
                 <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="mb-4 flex items-center justify-between">
                        <flux:heading size="lg">{{ __('Transaksi Terbaru') }}</flux:heading>
                        <flux:link href="{{ route('transactions.index') }}" class="text-xs font-semibold">
                            {{ __('View All') }}
                        </flux:link>
                    </div>
                    
                    <div class="space-y-4">
                        @forelse($this->recentTransactions as $transaction)
                            <div class="flex items-center justify-between rounded-lg border border-zinc-50 p-3 dark:border-zinc-700/50">
                                <div class="flex items-center gap-3">
                                    <div class="flex size-8 items-center justify-center rounded-full bg-zinc-100 text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400">
                                        <flux:icon name="document-text" variant="micro" />
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium">{{ $transaction->invoice_number }}</p>
                                        <p class="text-xs text-zinc-500">{{ $transaction->created_at->diffForHumans() }}</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-bold">{{ Number::currency($transaction->total_amount, 'IDR', 'id') }}</p>
                                    <p class="text-xs text-emerald-600 dark:text-emerald-400">{{ __($transaction->payment_method) }}</p>
                                </div>
                            </div>
                        @empty
                            <p class="py-4 text-center text-sm text-zinc-500">{{ __('No transactions found.') }}</p>
                        @endforelse
                    </div>
                </div>

                {{-- Top Selling Products --}}
                <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="mb-4 flex items-center justify-between">
                        <flux:heading size="lg">{{ __('Produk Terlaris') }}</flux:heading>
                    </div>

                    <div class="space-y-4">
                        @forelse($this->topSellingProducts as $item)
                            <div class="flex items-center justify-between rounded-lg border border-zinc-50 p-3 dark:border-zinc-700/50">
                                <div class="flex items-center gap-3">
                                    <div class="flex size-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-900/20 dark:text-emerald-400">
                                        {{ $loop->iteration }}
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium">{{ $item->product?->name ?? __('Unknown') }}</p>
                                        <p class="text-xs text-zinc-500">{{ $item->product?->category?->name ?? '-' }}</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-bold">{{ $item->total_qty }}</p>
                                    <p class="text-xs text-zinc-500">{{ __('items') }}</p>
                                </div>
                            </div>
                        @empty
                            <p class="py-4 text-center text-sm text-zinc-500">{{ __('No data available.') }}</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>

        @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
        <script>
            document.addEventListener('livewire:initialized', () => {
                const isDark = document.documentElement.classList.contains('dark');
                const themeColor = isDark ? '#f4f4f5' : '#18181b';
                const gridColor = isDark ? '#3f3f46' : '#e4e4e7';

                const options = {
                    series: [{
                        name: '{{ __('Revenue') }}',
                        data: @js($this->salesTrendData['series'])
                    }],
                    chart: {
                        type: 'area',
                        height: 300,
                        toolbar: { show: false },
                        zoom: { enabled: false },
                        fontFamily: 'Inter, sans-serif',
                        background: 'transparent'
                    },
                    dataLabels: { enabled: false },
                    stroke: { curve: 'smooth', width: 2, colors: [themeColor] },
                    fill: {
                        type: 'gradient',
                        gradient: {
                            shadeIntensity: 1,
                            opacityFrom: 0.45,
                            opacityTo: 0.05,
                            stops: [20, 100],
                            colorStops: [
                                { offset: 0, color: themeColor, opacity: 0.4 },
                                { offset: 100, color: themeColor, opacity: 0 }
                            ]
                        }
                    },
                    xaxis: {
                        categories: @js($this->salesTrendData['labels']),
                        axisBorder: { show: false },
                        axisTicks: { show: false },
                        labels: {
                            style: { colors: '#71717a' }
                        }
                    },
                    yaxis: {
                        labels: {
                            style: { colors: '#71717a' },
                            formatter: function (val) {
                                return Number(val).toLocaleString('id-ID', { maximumFractionDigits: 0 });
                            }
                        }
                    },
                    tooltip: {
                        theme: isDark ? 'dark' : 'light',
                        y: {
                            formatter: function (val) {
                                return 'Rp ' + Number(val).toLocaleString('id-ID');
                            }
                        }
                    },
                    grid: {
                        borderColor: gridColor,
                        strokeDashArray: 4,
                        padding: { left: 0, right: 0 }
                    },
                    markers: {
                        size: 4,
                        colors: [themeColor],
                        strokeColors: isDark ? '#18181b' : '#fff',
                        strokeWidth: 2,
                        hover: { size: 6 }
                    }
                };

                const chart = new ApexCharts(document.querySelector("#salesTrendChart"), options);
                chart.render();
            });
        </script>
        @endpush
    @endvolt
</x-layouts::app>
