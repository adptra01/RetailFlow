<?php

use App\Models\Ingredient;
use Flux\Flux;
use Livewire\WithPagination;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

name('ingredients.index');
middleware('auth');
middleware('verified');
uses(WithPagination::class);

state(['search' => '', 'showCreateModal' => false, 'showEditModal' => false, 'editingIngredient' => null]);

state([
    'name' => '',
    'sku' => '',
    'unit' => 'pcs',
    'cost_minor' => 0,
    'stock_qty' => 0,
    'min_stock_qty' => 0,
    'is_active' => true,
    'notes' => '',
]);

$ingredients = computed(function () {
    return Ingredient::query()
        ->where(function ($q) {
            if ($this->search) {
                $q->where('name', 'like', '%' . $this->search . '%')
                  ->orWhere('sku', 'like', '%' . $this->search . '%');
            }
        })
        ->orderBy('name')
        ->paginate(15);
});

$openCreateModal = function () {
    $this->reset(['editingIngredient', 'name', 'sku', 'unit', 'cost_minor', 'stock_qty', 'min_stock_qty', 'is_active', 'notes']);
    $this->unit = 'pcs';
    $this->stock_qty = 0;
    $this->min_stock_qty = 0;
    $this->is_active = true;
    $this->showCreateModal = true;
};

$openEditModal = function (Ingredient $ingredient) {
    $this->editingIngredient = $ingredient;
    $this->name = $ingredient->name;
    $this->sku = $ingredient->sku;
    $this->unit = $ingredient->unit;
    $this->cost_minor = $ingredient->cost_minor;
    $this->stock_qty = $ingredient->stock_qty;
    $this->min_stock_qty = $ingredient->min_stock_qty;
    $this->is_active = $ingredient->is_active;
    $this->notes = $ingredient->notes;
    $this->showEditModal = true;
};

$save = function () {
    $validated = $this->validate([
        'name' => 'required|string|max:200',
        'sku' => 'nullable|string|max:50',
        'unit' => 'required|string|max:20',
        'cost_minor' => 'required|integer|min:0',
        'stock_qty' => 'required|integer|min:0',
        'min_stock_qty' => 'required|integer|min:0',
        'is_active' => 'boolean',
        'notes' => 'nullable|string',
    ]);

    $ingredient = $this->editingIngredient ?? new Ingredient();
    $ingredient->fill($validated);
    $ingredient->save();

    Flux::toast(variant: 'success', text: $this->editingIngredient ? __('Ingredient updated.') : __('Ingredient created.'));
    $this->showCreateModal = false;
    $this->showEditModal = false;
    $this->reset(['editingIngredient', 'name', 'sku', 'unit', 'cost_minor', 'stock_qty', 'min_stock_qty', 'is_active', 'notes']);
};

$confirmDelete = function (Ingredient $ingredient) {
    $ingredient->delete();
    Flux::toast(variant: 'success', text: __('Ingredient deleted.'));
};

?>

<x-layouts::app :title="__('Ingredients')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="{{ route('dashboard') }}">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Ingredients') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="xl">{{ __('Ingredients') }}</flux:heading>
                    <flux:subheading>{{ __('Bahan baku untuk resep menu dan perhitungan HPP') }}</flux:subheading>
                </div>
                <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
                    {{ __('Add Ingredient') }}
                </flux:button>
            </div>

            <flux:input size="md" wire:model.live.debounce.300ms="search" type="search"
                placeholder="{{ __('Search ingredients...') }}" />

            <flux:card class="!p-0">
                <flux:table :paginate="$this->ingredients">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Name') }}</flux:table.column>
                        <flux:table.column>{{ __('SKU') }}</flux:table.column>
                        <flux:table.column>{{ __('Unit') }}</flux:table.column>
                        <flux:table.column>{{ __('Stock') }}</flux:table.column>
                        <flux:table.column>{{ __('Cost / Unit') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column>{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($this->ingredients as $ingredient)
                            <flux:table.row :key="$ingredient->id">
                                <flux:table.cell>
                                    <div class="font-medium">{{ $ingredient->name }}</div>
                                    <div class="text-xs text-zinc-500">{{ $ingredient->notes ?? '' }}</div>
                                </flux:table.cell>
                                <flux:table.cell class="font-mono text-xs">{{ $ingredient->sku ?? '-' }}</flux:table.cell>
                                <flux:table.cell class="text-xs">{{ $ingredient->unit }}</flux:table.cell>
                                <flux:table.cell>
                                    <div class="flex items-center gap-2">
                                        <span class="{{ $ingredient->stock_qty <= $ingredient->min_stock_qty ? 'text-red-600 font-medium' : '' }}">
                                            {{ number_format($ingredient->stock_qty) }}
                                        </span>
                                        @if ($ingredient->stock_qty <= $ingredient->min_stock_qty)
                                            <flux:badge color="red" size="sm" inset="top bottom">{{ __('Low') }}</flux:badge>
                                        @endif
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell class="font-medium">{{ Number::currency($ingredient->cost_minor, 'IDR', 'id') }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge :color="$ingredient->is_active ? 'green' : 'zinc'" size="sm" inset="top bottom">
                                        {{ $ingredient->is_active ? __('Active') : __('Inactive') }}
                                    </flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:button size="xs" variant="ghost" wire:click="openEditModal({{ $ingredient->id }})">
                                        {{ __('Edit') }}
                                    </flux:button>
                                    <flux:button size="xs" variant="danger" wire:click="confirmDelete({{ $ingredient->id }})"
                                        wire:confirm="{{ __('Hapus bahan baku ini?') }}">
                                        {{ __('Delete') }}
                                    </flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="7" class="text-center text-zinc-500 py-6">
                                    {{ __('Belum ada bahan baku.') }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>

            <flux:modal name="create-ingredient" :show="$showCreateModal" variant="flyout">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('New Ingredient') }}</flux:heading>
                        <flux:subheading>{{ __('Simpan bahan baku untuk dipakai di resep menu.') }}</flux:subheading>
                    </div>

                    <flux:input wire:model="name" :label="__('Name')" placeholder="{{ __('Contoh: Tepung Terigu') }}" />
                    <flux:input wire:model="sku" :label="__('SKU')" placeholder="{{ __('Opsional') }}" />
                    <div class="grid grid-cols-2 gap-4">
                        <flux:select wire:model="unit" :label="__('Unit')">
                            <flux:select.option value="pcs">pcs</flux:select.option>
                            <flux:select.option value="gr">gr</flux:select.option>
                            <flux:select.option value="ml">ml</flux:select.option>
                            <flux:select.option value="kg">kg</flux:select.option>
                            <flux:select.option value="pack">pack</flux:select.option>
                        </flux:select>
                        <flux:input wire:model="cost_minor" type="number" inputmode="numeric" :label="__('Cost per Unit (Rp)')" />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <flux:input wire:model="stock_qty" type="number" inputmode="numeric" :label="__('Stock')" />
                        <flux:input wire:model="min_stock_qty" type="number" inputmode="numeric" :label="__('Min Stock')" />
                    </div>
                    <flux:switch wire:model="is_active" :label="__('Active')" />
                    <flux:textarea wire:model="notes" :label="__('Notes')" />

                    <div class="flex justify-end gap-2">
                        <flux:button variant="ghost" wire:click="$set('showCreateModal', false)">{{ __('Cancel') }}</flux:button>
                        <flux:button variant="primary" wire:click="save">{{ __('Save Ingredient') }}</flux:button>
                    </div>
                </div>
            </flux:modal>

            <flux:modal name="edit-ingredient" :show="$showEditModal" variant="flyout">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Edit Ingredient') }}</flux:heading>
                        <flux:subheading>{{ __('Perbarui data bahan baku.') }}</flux:subheading>
                    </div>

                    <flux:input wire:model="name" :label="__('Name')" />
                    <flux:input wire:model="sku" :label="__('SKU')" placeholder="{{ __('Opsional') }}" />
                    <div class="grid grid-cols-2 gap-4">
                        <flux:select wire:model="unit" :label="__('Unit')">
                            <flux:select.option value="pcs">pcs</flux:select.option>
                            <flux:select.option value="gr">gr</flux:select.option>
                            <flux:select.option value="ml">ml</flux:select.option>
                            <flux:select.option value="kg">kg</flux:select.option>
                            <flux:select.option value="pack">pack</flux:select.option>
                        </flux:select>
                        <flux:input wire:model="cost_minor" type="number" inputmode="numeric" :label="__('Cost per Unit (Rp)')" />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <flux:input wire:model="stock_qty" type="number" inputmode="numeric" :label="__('Stock')" />
                        <flux:input wire:model="min_stock_qty" type="number" inputmode="numeric" :label="__('Min Stock')" />
                    </div>
                    <flux:switch wire:model="is_active" :label="__('Active')" />
                    <flux:textarea wire:model="notes" :label="__('Notes')" />

                    <div class="flex justify-end gap-2">
                        <flux:button variant="ghost" wire:click="$set('showEditModal', false)">{{ __('Cancel') }}</flux:button>
                        <flux:button variant="primary" wire:click="save">{{ __('Save Ingredient') }}</flux:button>
                    </div>
                </div>
            </flux:modal>
        </div>
    @endvolt
</x-layouts::app>