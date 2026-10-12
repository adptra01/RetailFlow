<?php

use App\Actions\Orders\AdvanceOrderStatus;
use App\Exceptions\Orders\InvalidOrderTransition;
use App\Models\Order;
use App\Models\Menu;
use Flux\Flux;
use Livewire\WithPagination;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

name('orders.index');
middleware('auth');
middleware('verified');
uses(WithPagination::class);

state([
    'search' => '',
    'status' => '',
])->url();

state([
    'showDetailModal' => false,
    'viewOrder' => null,
    'showPaymentModal' => false,
    'payingOrder' => null,
    'paymentAmount' => '',
    'paymentMethod' => 'cash',
    'paymentNote' => '',
]);

$orders = computed(function () {
    return Order::query()
        ->with(['user', 'customer', 'items.menu'])
        ->where(function ($q) {
            if ($this->search) {
                $q->where('order_number', 'like', '%' . $this->search . '%')
                  ->orWhere('customer_name', 'like', '%' . $this->search . '%')
                  ->orWhere('table_number', 'like', '%' . $this->search . '%');
            }
            if ($this->status) {
                $q->where('status', $this->status);
            }
        })
        ->orderByDesc('created_at')
        ->paginate(15);
});

$statusOptions = computed(function () {
    return [
        'pending' => __('Pending'),
        'preparing' => __('Preparing'),
        'ready' => __('Ready'),
        'served' => __('Served'),
        'paid' => __('Paid'),
        'cancelled' => __('Cancelled'),
    ];
});

$openDetailModal = function (Order $order) {
    $this->viewOrder = $order->load(['items.menu', 'user', 'customer']);
    $this->showDetailModal = true;
};

$openPaymentModal = function (Order $order) {
    $this->payingOrder = $order;
    $this->paymentAmount = $order->total_minor - $order->paid_minor;
    $this->paymentMethod = 'cash';
    $this->paymentNote = '';
    $this->showPaymentModal = true;
};

$updateStatus = function (Order $order, string $status) {
    try {
        $result = app(AdvanceOrderStatus::class)->handle([
            'order_id' => $order->id,
            'status' => $status,
            'user_id' => auth()->id(),
        ]);

        $label = $this->statusOptions[$result['status_to']] ?? $result['status_to'];
        $text = __('Order status updated to :status', ['status' => $label]);

        if ($result['inventory_adjusted']) {
            $text .= ' · ' . __('stok bahan disesuaikan');
        }

        Flux::toast(variant: 'success', text: $text);
    } catch (InvalidOrderTransition $e) {
        Flux::toast(variant: 'danger', text: $e->getMessage());
    }
};

$recordPayment = function () {
    $this->validate([
        'paymentAmount' => 'required|numeric|min:0',
        'payingOrder' => 'required',
    ]);

    $order = $this->payingOrder;

    $amountMinor = (int) round($this->paymentAmount);
    $remaining = $order->total_minor - $order->paid_minor;

    if ($amountMinor > $remaining) {
        Flux::toast(variant: 'error', text: __('Payment exceeds remaining balance.'));
        return;
    }

    $newPaidMinor = $order->paid_minor + $amountMinor;
    $newPaymentStatus = $newPaidMinor >= $order->total_minor ? 'paid' : 'partial';

    $order->update([
        'paid_minor' => $newPaidMinor,
        'change_minor' => max(0, $newPaidMinor - $order->total_minor),
        'payment_status' => $newPaymentStatus,
        'status' => $newPaymentStatus === 'paid' ? 'paid' : $order->status,
    ]);

    // Create payment record could be added here if needed

    Flux::toast(variant: 'success', text: __('Payment recorded.'));
    $this->showPaymentModal = false;
    $this->reset(['payingOrder', 'paymentAmount', 'paymentMethod', 'paymentNote']);
};

?>

<x-layouts::app :title="__('Orders')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="{{ route('dashboard') }}">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Orders') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <flux:heading size="xl">{{ __('Orders') }}</flux:heading>
                    <flux:subheading>{{ __('Manage food and beverage orders') }}</flux:subheading>
                </div>
                <div class="flex flex-wrap gap-2">
                    <flux:select wire:model.live="status" class="w-40">
                        <option value="">{{ __('All Status') }}</option>
                        @foreach ($this->statusOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model.live.debounce.300ms="search" type="search"
                        placeholder="{{ __('Search orders...') }}" class="w-64" />
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-zinc-200 bg-zinc-50 text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">
                        <tr>
                            <th class="p-3 font-medium">{{ __('Order #') }}</th>
                            <th class="p-3 font-medium">{{ __('Customer') }}</th>
                            <th class="p-3 font-medium">{{ __('Table') }}</th>
                            <th class="p-3 font-medium text-right">{{ __('Total') }}</th>
                            <th class="p-3 font-medium text-right">{{ __('Paid') }}</th>
                            <th class="p-3 font-medium">{{ __('Status') }}</th>
                            <th class="p-3 font-medium">{{ __('Payment') }}</th>
                            <th class="p-3 font-medium">{{ __('Time') }}</th>
                            <th class="p-3 font-medium">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                        @forelse ($this->orders as $order)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                <td class="p-3 font-mono text-zinc-900 dark:text-zinc-100">{{ $order->order_number }}</td>
                                <td class="p-3">
                                    <div class="font-medium">{{ $order->customer_name ?: '—' }}</div>
                                    @if ($order->customer)
                                        <div class="text-xs text-zinc-500">{{ $order->customer->phone }}</div>
                                    @endif
                                </td>
                                <td class="p-3">
                                    @if ($order->table_number)
                                        <flux:badge size="sm">{{ $order->table_number }}</flux:badge>
                                    @else
                                        <span class="text-zinc-400">—</span>
                                    @endif
                                </td>
                                <td class="p-3 text-right font-mono">{{ Number::currency($order->total_minor, 'IDR', 'id') }}</td>
                                <td class="p-3 text-right font-mono text-green-600 dark:text-green-400">
                                    {{ Number::currency($order->paid_minor, 'IDR', 'id') }}
                                </td>
                                <td class="p-3">
                                    @php
                                        $statusColors = [
                                            'pending' => 'amber',
                                            'preparing' => 'blue',
                                            'ready' => 'indigo',
                                            'served' => 'green',
                                            'paid' => 'emerald',
                                            'cancelled' => 'rose',
                                        ];
                                        $color = $statusColors[$order->status] ?? 'zinc';
                                    @endphp
                                    <flux:badge size="sm" color="{{ $color }}">{{ $this->statusOptions[$order->status] ?? $order->status }}</flux:badge>
                                </td>
                                <td class="p-3">
                                    @php
                                        $payColors = [
                                            'unpaid' => 'rose',
                                            'partial' => 'amber',
                                            'paid' => 'emerald',
                                        ];
                                        $payColor = $payColors[$order->payment_status] ?? 'zinc';
                                    @endphp
                                    <flux:badge size="sm" color="{{ $payColor }}">{{ $order->payment_status }}</flux:badge>
                                </td>
                                <td class="p-3 text-zinc-500 whitespace-nowrap">{{ $order->created_at->format('d M H:i') }}</td>
                                <td class="p-3">
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="top bottom" />
                                        <flux:menu>
                                            <flux:menu.item icon="eye" wire:click="openDetailModal({{ $order->id }})">
                                                {{ __('View Details') }}
                                            </flux:menu.item>
                                            @if ($order->status !== 'paid' && $order->status !== 'cancelled')
                                                <flux:menu.item icon="fire" wire:click="updateStatus({{ $order->id }}, 'preparing')">
                                                    {{ __('Mark Preparing') }}
                                                </flux:menu.item>
                                                <flux:menu.item icon="check-circle" wire:click="updateStatus({{ $order->id }}, 'ready')">
                                                    {{ __('Mark Ready') }}
                                                </flux:menu.item>
                                                <flux:menu.item icon="hand-raised" wire:click="updateStatus({{ $order->id }}, 'served')">
                                                    {{ __('Mark Served') }}
                                                </flux:menu.item>
                                                <flux:menu.separator />
                                                <flux:menu.item icon="credit-card" wire:click="openPaymentModal({{ $order->id }})">
                                                    {{ __('Record Payment') }}
                                                </flux:menu.item>
                                            @endif
                                            @if ($order->status === 'pending')
                                                <flux:menu.separator />
                                                <flux:menu.item icon="x-circle" variant="danger" wire:click="updateStatus({{ $order->id }}, 'cancelled')">
                                                    {{ __('Cancel Order') }}
                                                </flux:menu.item>
                                            @endif
                                        </flux:menu>
                                    </flux:dropdown>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="p-8 text-center text-zinc-500">{{ __('No orders found.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>

                <div class="p-4 border-t border-zinc-200 dark:border-zinc-700">
                    {{ $this->orders->links() }}
                </div>
            </div>

            {{-- Detail Modal --}}
            <flux:modal wire:model.self="showDetailModal" class="max-w-3xl">
                @if ($this->viewOrder)
                    <div class="space-y-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <flux:heading size="lg">{{ __('Order Details') }}</flux:heading>
                                <flux:subheading>{{ $this->viewOrder->order_number }}</flux:subheading>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                            <div>
                                <span class="text-zinc-500">{{ __('Customer') }}</span>
                                <div class="font-medium">{{ $this->viewOrder->customer_name ?: '—' }}</div>
                            </div>
                            <div>
                                <span class="text-zinc-500">{{ __('Table') }}</span>
                                <div class="font-medium">{{ $this->viewOrder->table_number ?: '—' }}</div>
                            </div>
                            <div>
                                <span class="text-zinc-500">{{ __('Cashier') }}</span>
                                <div class="font-medium">{{ $this->viewOrder->user?->name ?? '—' }}</div>
                            </div>
                            <div>
                                <span class="text-zinc-500">{{ __('Time') }}</span>
                                <div class="font-medium">{{ $this->viewOrder->created_at->format('d M Y H:i') }}</div>
                            </div>
                        </div>

                        <div class="border-t border-zinc-200 dark:border-zinc-700 pt-4">
                            <flux:heading size="sm">{{ __('Items') }}</flux:heading>
                            <table class="w-full text-sm mt-2">
                                <thead class="border-b border-zinc-200 dark:border-zinc-700">
                                    <tr class="text-zinc-500">
                                        <th class="p-2 text-left">{{ __('Item') }}</th>
                                        <th class="p-2 text-center">{{ __('Qty') }}</th>
                                        <th class="p-2 text-right">{{ __('Price') }}</th>
                                        <th class="p-2 text-right">{{ __('Subtotal') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                                    @foreach ($this->viewOrder->items as $item)
                                        <tr>
                                            <td class="p-2">
                                                <div class="font-medium">{{ $item->menu_name }}</div>
                                                @if ($item->notes)
                                                    <div class="text-xs text-zinc-500">{{ $item->notes }}</div>
                                                @endif
                                            </td>
                                            <td class="p-2 text-center">{{ $item->quantity }}</td>
                                            <td class="p-2 text-right">{{ Number::currency($item->unit_price_minor, 'IDR', 'id') }}</td>
                                            <td class="p-2 text-right font-medium">{{ Number::currency($item->subtotal_minor, 'IDR', 'id') }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="border-t border-zinc-200 dark:border-t-zinc-700">
                                    <tr>
                                        <td colspan="3" class="p-2 text-right font-medium">{{ __('Subtotal') }}</td>
                                        <td class="p-2 text-right font-medium">{{ Number::currency($this->viewOrder->subtotal_minor, 'IDR', 'id') }}</td>
                                    </tr>
                                    @if ($this->viewOrder->discount_minor > 0)
                                        <tr>
                                            <td colspan="3" class="p-2 text-right text-red-600">{{ __('Discount') }}</td>
                                            <td class="p-2 text-right text-red-600">-{{ Number::currency($this->viewOrder->discount_minor, 'IDR', 'id') }}</td>
                                        </tr>
                                    @endif
                                    <tr class="font-semibold">
                                        <td colspan="3" class="p-2 text-right">{{ __('Total') }}</td>
                                        <td class="p-2 text-right">{{ Number::currency($this->viewOrder->total_minor, 'IDR', 'id') }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

                        @if ($this->viewOrder->notes)
                            <div class="p-4 bg-zinc-50 dark:bg-zinc-800 rounded-lg">
                                <span class="text-zinc-500">{{ __('Notes') }}: </span>
                                <span>{{ $this->viewOrder->notes }}</span>
                            </div>
                        @endif

                        <div class="flex justify-end gap-2 pt-4 border-t border-zinc-200 dark:border-t-zinc-700">
                            <flux:modal.close><flux:button variant="filled">{{ __('Close') }}</flux:button></flux:modal.close>
                        </div>
                    </div>
                @endif
            </flux:modal>

            {{-- Payment Modal --}}
            <flux:modal wire:model.self="showPaymentModal" class="max-w-lg">
                @if ($this->payingOrder)
                    <div class="space-y-6">
                        <div>
                            <flux:heading size="lg">{{ __('Record Payment') }}</flux:heading>
                            <flux:subheading>{{ $this->payingOrder->order_number }} — {{ Number::currency($this->payingOrder->total_minor - $this->payingOrder->paid_minor, 'IDR', 'id') }} {{ __('remaining') }}</flux:subheading>
                        </div>

                        <flux:input wire:model="paymentAmount" type="number" step="1" min="0"
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
                            <flux:button variant="primary" wire:click="recordPayment" :disabled="!$paymentAmount">
                                {{ __('Save Payment') }}
                            </flux:button>
                        </div>
                    </div>
                @endif
            </flux:modal>
        </div>
    @endvolt
</x-layouts::app>