<?php

use App\Models\Product;
use App\Models\StockOpname;
use Flux\Flux;
use Livewire\WithPagination;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

name('stock-opnames.index');
middleware('auth');
middleware('verified');
uses(WithPagination::class);

state([
    'search' => '',
    'showCreateModal' => false,
    'opnameSearch' => '',
    'opnameResults' => [],
    'selectedOpnames' => [],
]);

$opnames = computed(function () {
    return StockOpname::query()
        ->with('product', 'user')
        ->whereHas('product', fn ($q) => $q->where('name', 'like', '%' . $this->search . '%'))
        ->orderByDesc('created_at')
        ->paginate(20);
});

$openCreate = function () {
    $this->opnameSearch = '';
    $this->opnameResults = [];
    $this->selectedOpnames = [];
    $this->showCreateModal = true;
};

$updatedOpnameSearch = function () {
    $this->searchProducts();
};

$searchProducts = function () {
    if (empty($this->opnameSearch)) {
        $this->opnameResults = [];
        return;
    }
    $this->opnameResults = Product::where('is_unlimited_stock', false)
        ->where(function ($q) {
            $q->where('name', 'like', '%' . $this->opnameSearch . '%')
              ->orWhere('sku', 'like', '%' . $this->opnameSearch . '%');
        })
        ->limit(10)
        ->get()
        ->map(fn ($p) => [
            'product_id' => $p->id,
            'name' => $p->name,
            'system_stock' => $p->stock,
            'actual_stock' => $p->stock,
            'difference' => 0,
            'reason' => '',
        ])
        ->toArray();
};

$toggleProduct = function ($productId) {
    $idx = array_search($productId, array_column($this->selectedOpnames, 'product_id'));
    if ($idx !== false) {
        array_splice($this->selectedOpnames, $idx, 1);
    } else {
        $product = Product::find($productId);
        if ($product) {
            $this->selectedOpnames[] = [
                'product_id' => $product->id,
                'name' => $product->name,
                'system_stock' => $product->stock,
                'actual_stock' => $product->stock,
                'difference' => 0,
                'reason' => '',
            ];
        }
    }
};

$updateActual = function ($index, $value) {
    if (isset($this->selectedOpnames[$index])) {
        $this->selectedOpnames[$index]['actual_stock'] = (int) $value;
        $this->selectedOpnames[$index]['difference'] = (int) $value - $this->selectedOpnames[$index]['system_stock'];
    }
};

$saveOpname = function () {
    if (empty($this->selectedOpnames)) return;

    foreach ($this->selectedOpnames as $data) {
        $product = Product::find($data['product_id']);
        if (!$product || $product->is_unlimited_stock) continue;

        StockOpname::create([
            'product_id' => $data['product_id'],
            'system_stock' => $data['system_stock'],
            'actual_stock' => $data['actual_stock'],
            'difference' => $data['difference'],
            'reason' => $data['reason'],
            'user_id' => auth()->id(),
        ]);

        if ($data['difference'] != 0) {
            $product->update(['stock' => $data['actual_stock']]);
        }
    }

    $this->showCreateModal = false;
    Flux::toast(variant: 'success', text: __('Stock opname saved.'));
};

?>

<x-layouts::app :title="__('Stock Opname')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Stock Opname') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="xl">{{ __('Stock Opname') }}</flux:heading>
                    <flux:subheading>{{ __('Physical stock count vs system') }}</flux:subheading>
                </div>
                <flux:button variant="primary" icon="plus" wire:click="openCreate">
                    {{ __('New Opname') }}
                </flux:button>
            </div>

            <flux:input size="md" wire:model.live="search" type="search" placeholder="{{ __('Search by product name...') }}" />

            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <flux:table :paginate="$this->opnames">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Date') }}</flux:table.column>
                        <flux:table.column>{{ __('Product') }}</flux:table.column>
                        <flux:table.column>{{ __('System') }}</flux:table.column>
                        <flux:table.column>{{ __('Actual') }}</flux:table.column>
                        <flux:table.column>{{ __('Diff') }}</flux:table.column>
                        <flux:table.column>{{ __('Reason') }}</flux:table.column>
                        <flux:table.column>{{ __('User') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->opnames as $o)
                            <flux:table.row :key="$o->id">
                                <flux:table.cell class="text-xs text-zinc-500">{{ $o->created_at->format('d M H:i') }}</flux:table.cell>
                                <flux:table.cell class="font-medium">{{ $o->product?->name ?? '-' }}</flux:table.cell>
                                <flux:table.cell class="font-mono">{{ $o->system_stock }}</flux:table.cell>
                                <flux:table.cell class="font-mono">{{ $o->actual_stock }}</flux:table.cell>
                                <flux:table.cell>
                                    <span @class(['font-mono font-medium', 'text-green-600' => $o->difference > 0, 'text-red-600' => $o->difference < 0])>
                                        {{ $o->difference > 0 ? '+' : '' }}{{ $o->difference }}
                                    </span>
                                </flux:table.cell>
                                <flux:table.cell class="max-w-[200px] truncate text-xs text-zinc-500">{{ $o->reason ?: '-' }}</flux:table.cell>
                                <flux:table.cell class="text-xs text-zinc-500">{{ $o->user?->name ?? '-' }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>

            {{-- Create Opname Modal --}}
            <flux:modal wire:model.self="showCreateModal" class="max-w-3xl">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('New Stock Opname') }}</flux:heading>
                        <flux:subheading>{{ __('Search products, enter actual stock, and save.') }}</flux:subheading>
                    </div>

                    <flux:input wire:model.live="opnameSearch" type="search" placeholder="{{ __('Search products...') }}" />

                    @if (!empty($this->opnameResults))
                        <div class="divide-y divide-zinc-100 rounded-lg border dark:divide-zinc-700 dark:border-zinc-700">
                            @foreach ($this->opnameResults as $result)
                                @php $selected = in_array($result['product_id'], array_column($this->selectedOpnames, 'product_id')); @endphp
                                <label class="flex cursor-pointer items-center gap-3 px-4 py-2 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                    <input type="checkbox" {{ $selected ? 'checked' : '' }} wire:click="toggleProduct({{ $result['product_id'] }})" class="h-4 w-4 rounded border-zinc-300">
                                    <span class="flex-1 text-sm">{{ $result['name'] }}</span>
                                    <span class="text-xs text-zinc-500">Sistem: <strong>{{ $result['system_stock'] }}</strong></span>
                                </label>
                            @endforeach
                        </div>
                    @endif

                    @if (!empty($this->selectedOpnames))
                        <div class="overflow-x-auto rounded-lg border dark:border-zinc-700">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-b bg-zinc-50 text-left text-xs font-medium dark:border-zinc-700 dark:bg-zinc-800">
                                        <th class="px-3 py-2">{{ __('Product') }}</th>
                                        <th class="px-3 py-2 text-center">{{ __('System') }}</th>
                                        <th class="px-3 py-2 text-center">{{ __('Actual') }}</th>
                                        <th class="px-3 py-2 text-center">{{ __('Diff') }}</th>
                                        <th class="px-3 py-2">{{ __('Reason') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($this->selectedOpnames as $index => $data)
                                        <tr class="border-b dark:border-zinc-800">
                                            <td class="px-3 py-2 font-medium">{{ $data['name'] }}</td>
                                            <td class="px-3 py-2 text-center font-mono">{{ $data['system_stock'] }}</td>
                                            <td class="px-3 py-2 text-center">
                                                <input type="number" min="0" value="{{ $data['actual_stock'] }}"
                                                    wire:change="updateActual({{ $index }}, $event.target.value)"
                                                    class="w-20 rounded border border-zinc-300 px-2 py-1 text-center text-sm dark:border-zinc-600 dark:bg-zinc-700">
                                            </td>
                                            <td class="px-3 py-2 text-center font-mono font-medium" @class(['text-green-600' => $data['difference'] > 0, 'text-red-600' => $data['difference'] < 0])>
                                                {{ $data['difference'] > 0 ? '+' : '' }}{{ $data['difference'] }}
                                            </td>
                                            <td class="px-3 py-2">
                                                <input type="text" wire:model="selectedOpnames.{{ $index }}.reason"
                                                    placeholder="{{ __('Reason...') }}"
                                                    class="w-full rounded border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-600 dark:bg-zinc-700">
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                        <flux:button variant="primary" wire:click="saveOpname" :disabled="empty($this->selectedOpnames)">
                            {{ __('Save Opname') }}
                        </flux:button>
                    </div>
                </div>
            </flux:modal>
        </div>
    @endvolt
</x-layouts::app>
