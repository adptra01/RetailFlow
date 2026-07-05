<?php

use App\Models\StockMovement;
use Livewire\WithPagination;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

name('stock-movements.index');
middleware('auth');
middleware('verified');
uses(WithPagination::class);

state(['search' => ''])->url();

$movements = computed(function () {
    return StockMovement::query()
        ->with('product', 'user')
        ->where(function ($q) {
            if ($this->search) {
                $q->whereHas('product', fn ($q) => $q->where('name', 'like', '%' . $this->search . '%'))
                  ->orWhere('reference_type', 'like', '%' . $this->search . '%');
            }
        })
        ->orderByDesc('created_at')
        ->paginate(20);
});

$refTypeLabel = fn ($type) => __(ucfirst(str_replace('_', ' ', $type)));

?>

<x-layouts::app :title="__('Stock Movements')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Stock Movements') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div>
                <flux:heading size="xl">{{ __('Stock Movements') }}</flux:heading>
                <flux:subheading>{{ __('History of all stock changes') }}</flux:subheading>
            </div>

            <flux:input size="md" wire:model.live="search" type="search" placeholder="{{ __('Search by product or type...') }}" />

            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
                <flux:table :paginate="$this->movements">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Date') }}</flux:table.column>
                        <flux:table.column>{{ __('Product') }}</flux:table.column>
                        <flux:table.column>{{ __('Type') }}</flux:table.column>
                        <flux:table.column>{{ __('Change') }}</flux:table.column>
                        <flux:table.column>{{ __('Before') }}</flux:table.column>
                        <flux:table.column>{{ __('After') }}</flux:table.column>
                        <flux:table.column>{{ __('User') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->movements as $m)
                            <flux:table.row :key="$m->id">
                                <flux:table.cell class="text-xs text-zinc-500">{{ $m->created_at->format('d M H:i') }}</flux:table.cell>
                                <flux:table.cell class="font-medium">{{ $m->product?->name ?? '-' }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" inset="top bottom">{{ $this->refTypeLabel($m->reference_type) }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <span @class(['font-mono font-medium', 'text-green-600' => $m->quantity_change > 0, 'text-red-600' => $m->quantity_change < 0])>
                                        {{ $m->quantity_change > 0 ? '+' : '' }}{{ $m->quantity_change }}
                                    </span>
                                </flux:table.cell>
                                <flux:table.cell class="font-mono">{{ $m->before_stock }}</flux:table.cell>
                                <flux:table.cell class="font-mono">{{ $m->after_stock }}</flux:table.cell>
                                <flux:table.cell class="text-xs text-zinc-500">{{ $m->user?->name ?? 'System' }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>
    @endvolt
</x-layouts::app>
