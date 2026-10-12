<?php

use App\Actions\Menus\UpdateRecipe;
use App\Models\Ingredient;
use App\Models\Menu;
use App\Models\Category;
use Flux\Flux;
use Livewire\WithPagination;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

name('menu.index');
middleware('auth');
middleware('verified');
uses(WithPagination::class);

state([
    'search' => '', 'category_id' => '', 'showCreateModal' => false, 'showEditModal' => false, 'editingMenu' => null,
    'showRecipeModal' => false, 'recipeMenuId' => null, 'recipeMenuName' => '', 'recipeItems' => [],
    'newIngredientId' => '', 'newIngredientQty' => 1,
]);

$menus = computed(function () {
    return Menu::query()
        ->with(['category', 'recipeIngredients.ingredient'])
        ->where(function ($q) {
            if ($this->search) {
                $q->where('name', 'like', '%' . $this->search . '%')
                  ->orWhere('sku', 'like', '%' . $this->search . '%')
                  ->orWhere('barcode', 'like', '%' . $this->search . '%');
            }
            if ($this->category_id) {
                $q->where('category_id', $this->category_id);
            }
        })
        ->orderBy('name')
        ->paginate(15);
});

$categories = computed(function () {
    return Category::orderBy('name')->get();
});

$confirmDelete = function (Menu $menu) {
    $menu->delete();
    Flux::toast(variant: 'success', text: __('Menu deleted.'));
};

$openCreateModal = function () {
    $this->reset(['editingMenu', 'name', 'category_id', 'sku', 'barcode', 'description', 'price_minor', 'preparation_time', 'is_available', 'is_featured', 'image']);
    $this->is_available = true;
    $this->showCreateModal = true;
};

$openEditModal = function (Menu $menu) {
    $this->editingMenu = $menu;
    $this->name = $menu->name;
    $this->category_id = $menu->category_id;
    $this->sku = $menu->sku;
    $this->barcode = $menu->barcode;
    $this->description = $menu->description;
    $this->price_minor = $menu->price_minor;
    $this->preparation_time = $menu->preparation_time;
    $this->is_available = $menu->is_available;
    $this->is_featured = $menu->is_featured;
    $this->showEditModal = true;
};

$save = function () {
    $validated = $this->validate([
        'name' => 'required|string|max:200',
        'category_id' => 'nullable|exists:categories,id',
        'sku' => 'required|string|max:50|unique:menus,sku,' . ($this->editingMenu?->id ?? 'NULL'),
        'barcode' => 'nullable|string|max:50|unique:menus,barcode,' . ($this->editingMenu?->id ?? 'NULL'),
        'description' => 'nullable|string',
        'price_minor' => 'required|integer|min:0',
        'preparation_time' => 'nullable|integer|min:0|max:600',
        'is_available' => 'boolean',
        'is_featured' => 'boolean',
        'image' => 'nullable|image|max:2048',
    ]);

    $menu = $this->editingMenu ?? new Menu();
    $menu->fill($validated);

    if ($this->image) {
        $path = $this->image->store('menus', 'public');
        $menu->image = $path;
    }

    $menu->save();

    Flux::toast(variant: 'success', text: $this->editingMenu ? __('Menu updated.') : __('Menu created.'));
    $this->showCreateModal = false;
    $this->showEditModal = false;
    $this->reset(['editingMenu', 'name', 'category_id', 'sku', 'barcode', 'description', 'price_minor', 'preparation_time', 'is_available', 'is_featured', 'image']);
};

$ingredients = computed(fn () => Ingredient::query()->where('is_active', true)->orderBy('name')->get());

$openRecipeModal = function (Menu $menu) {
    $this->recipeMenuId = $menu->id;
    $this->recipeMenuName = $menu->name;
    $this->recipeItems = $menu->recipeIngredients->map(fn ($line) => [
        'ingredient_id' => (string) $line->ingredient_id,
        'name' => $line->ingredient?->name ?? __('Unknown'),
        'unit' => $line->ingredient?->unit ?? '',
        'cost_minor' => $line->ingredient?->cost_minor ?? 0,
        'quantity' => $line->quantity,
        'notes' => $line->notes ?? '',
    ])->values()->toArray();
    $this->newIngredientId = '';
    $this->newIngredientQty = 1;
    $this->showRecipeModal = true;
};

$addRecipeLine = function () {
    $this->validate([
        'newIngredientId' => 'required',
        'newIngredientQty' => 'required|integer|min:1',
    ]);

    $ingredient = $this->ingredients->firstWhere('id', (int) $this->newIngredientId);

    if (! $ingredient) {
        Flux::toast(variant: 'danger', text: __('Ingredient not found.'));

        return;
    }

    if (collect($this->recipeItems)->contains('ingredient_id', (string) $ingredient->id)) {
        Flux::toast(variant: 'danger', text: __('Bahan sudah ada di resep.'));

        return;
    }

    $this->recipeItems[] = [
        'ingredient_id' => (string) $ingredient->id,
        'name' => $ingredient->name,
        'unit' => $ingredient->unit,
        'cost_minor' => $ingredient->cost_minor,
        'quantity' => (int) $this->newIngredientQty,
        'notes' => '',
    ];

    $this->newIngredientId = '';
    $this->newIngredientQty = 1;
};

$removeRecipeLine = function (int $index) {
    unset($this->recipeItems[$index]);
    $this->recipeItems = array_values($this->recipeItems);
};

$saveRecipe = function () {
    $this->validate([
        'recipeItems.*.quantity' => 'required|integer|min:1',
    ]);

    if (empty($this->recipeItems)) {
        Flux::toast(variant: 'danger', text: __('Tambahkan minimal satu bahan baku.'));

        return;
    }

    app(UpdateRecipe::class)->handle([
        'menu_id' => (int) $this->recipeMenuId,
        'user_id' => auth()->id(),
        'items' => $this->recipeItems,
    ]);

    Flux::toast(variant: 'success', text: __('Recipe updated.'));
    $this->showRecipeModal = false;
    $this->reset(['recipeMenuId', 'recipeMenuName', 'recipeItems', 'newIngredientId', 'newIngredientQty']);
};

$recipeTotalMinor = computed(function () {
    return collect($this->recipeItems)->sum(fn ($line) => (int) $line['quantity'] * (int) $line['cost_minor']);
});

?>

<x-layouts::app :title="__('Menu')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="{{ route('dashboard') }}">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Menu') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="xl">{{ __('Menu Items') }}</flux:heading>
                    <flux:subheading>{{ __('Manage your food and beverage menu') }}</flux:subheading>
                </div>
                <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
                    {{ __('Add Menu Item') }}
                </flux:button>
            </div>

            <flux:input size="md" wire:model.live.debounce.300ms="search" type="search"
                placeholder="{{ __('Search menu items...') }}" />

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                @foreach ($this->menus as $menu)
                    <flux:card class="h-full flex flex-col">
                        @if ($menu->image)
                            <img src="{{ asset('storage/' . $menu->image) }}" alt="{{ $menu->name }}" class="w-full h-40 object-cover rounded-t-xl" />
                        @else
                            <div class="w-full h-40 bg-zinc-100 dark:bg-zinc-800 rounded-t-xl flex items-center justify-center">
                                <flux:icon name="shopping-bag" variant="micro" class="text-zinc-400 dark:text-zinc-500" />
                            </div>
                        @endif

                        <div class="p-4 flex flex-col flex-1">
                            <div class="flex items-start justify-between gap-2">
                                <flux:heading size="md" class="flex-1 truncate">{{ $menu->name }}</flux:heading>
                                @if ($menu->is_featured)
                                    <flux:badge size="xs" color="amber" class="shrink-0">{{ __('Featured') }}</flux:badge>
                                @endif
                            </div>

                            @if ($menu->description)
                                <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1 line-clamp-2">{{ $menu->description }}</p>
                            @endif

                            <div class="mt-2 flex items-center justify-between">
                                <flux:heading size="sm" class="text-green-600 dark:text-green-400">
                                    {{ Number::currency($menu->price_minor, 'IDR', 'id') }}
                                </flux:heading>
                                @if ($menu->preparation_time)
                                    <flux:badge size="xs" class="text-zinc-500 dark:text-zinc-400 bg-zinc-100 dark:bg-zinc-800">
                                        {{ $menu->preparation_time }} {{ __('min') }}
                                    </flux:badge>
                                @endif
                            </div>

                            <div class="mt-2 flex items-center gap-2 text-xs text-zinc-500">
                                <span>{{ $menu->category?->name ?? '—' }}</span>
                                <span>·</span>
                                <span class="{{ $menu->is_available ? 'text-green-600' : 'text-red-600' }}">
                                    {{ $menu->is_available ? __('Available') : __('Unavailable') }}
                                </span>
                            </div>

                            <div class="mt-2 flex items-center justify-between text-xs">
                                @if ($menu->recipeIngredients->isNotEmpty())
                                    @php
                                        $cogs = $menu->cogs_minor;
                                        $pct = $menu->price_minor > 0 ? round((($menu->price_minor - $cogs) / $menu->price_minor) * 100) : 0;
                                    @endphp
                                    <span class="text-zinc-500">{{ __('HPP') }}: {{ Number::currency($cogs, 'IDR', 'id') }}</span>
                                    <flux:badge size="xs" :color="$pct >= 0 ? 'green' : 'red'" inset="top bottom">
                                        {{ $pct >= 0 ? '+' : '' }}{{ $pct }}%
                                    </flux:badge>
                                @else
                                    <flux:badge size="xs" color="amber" inset="top bottom">{{ __('Belum ada resep') }}</flux:badge>
                                @endif
                            </div>
                        </div>

                        <div class="p-4 border-t border-zinc-200 dark:border-zinc-700 flex gap-2">
                            <flux:button variant="ghost" size="sm" wire:click="openEditModal({{ $menu->id }})" class="flex-1">
                                {{ __('Edit') }}
                            </flux:button>
                            <flux:button variant="filled" size="sm" wire:click="openRecipeModal({{ $menu->id }})" class="flex-1">
                                {{ __('Resep') }}
                            </flux:button>
                            <flux:button variant="ghost" size="sm" variant="danger" wire:click="confirmDelete({{ $menu->id }})" class="flex-1">
                                {{ __('Delete') }}
                            </flux:button>
                        </div>
                    </flux:card>
                @endforeach
            </div>

            {{ $this->menus->links() }}

            {{-- Create Modal --}}
            <flux:modal wire:model.self="showCreateModal" class="max-w-2xl">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Add Menu Item') }}</flux:heading>
                    </div>

                    <form wire:submit="save" class="space-y-4">
                        <flux:input wire:model="name" label="{{ __('Name') }}" required autofocus />
                        <flux:select wire:model="category_id" label="{{ __('Category') }}" searchable>
                            <option value="">{{ __('Select category') }}</option>
                            @foreach ($this->categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:input wire:model="sku" label="{{ __('SKU') }}" required />
                        <flux:input wire:model="barcode" label="{{ __('Barcode') }}" />
                        <flux:textarea wire:model="description" label="{{ __('Description') }}" rows="3" />
                        <flux:input wire:model="price_minor" type="number" label="{{ __('Price (Rp)') }}" required min="0" step="1" />
                        <flux:input wire:model="preparation_time" type="number" label="{{ __('Preparation Time (minutes)') }}" min="0" max="600" />
                        <div class="grid grid-cols-2 gap-4">
                            <flux:checkbox wire:model="is_available" label="{{ __('Available') }}" />
                            <flux:checkbox wire:model="is_featured" label="{{ __('Featured') }}" />
                        </div>
                        <flux:input wire:model="image" type="file" label="{{ __('Image') }}" accept="image/*" />

                        <div class="flex justify-end gap-2 pt-4">
                            <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                            <flux:button variant="primary" type="submit">{{ __('Save Menu Item') }}</flux:button>
                        </div>
                    </form>
                </div>
            </flux:modal>

            {{-- Edit Modal --}}
            <flux:modal wire:model.self="showEditModal" class="max-w-2xl">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Edit Menu Item') }}</flux:heading>
                    </div>

                    <form wire:submit="save" class="space-y-4">
                        <flux:input wire:model="name" label="{{ __('Name') }}" required />
                        <flux:select wire:model="category_id" label="{{ __('Category') }}" searchable>
                            <option value="">{{ __('Select category') }}</option>
                            @foreach ($this->categories as $category)
                                <option value="{{ $category->id }}">{{ $category->name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:input wire:model="sku" label="{{ __('SKU') }}" required />
                        <flux:input wire:model="barcode" label="{{ __('Barcode') }}" />
                        <flux:textarea wire:model="description" label="{{ __('Description') }}" rows="3" />
                        <flux:input wire:model="price_minor" type="number" label="{{ __('Price (Rp)') }}" required min="0" step="1" />
                        <flux:input wire:model="preparation_time" type="number" label="{{ __('Preparation Time (minutes)') }}" min="0" max="600" />
                        <div class="grid grid-cols-2 gap-4">
                            <flux:checkbox wire:model="is_available" label="{{ __('Available') }}" />
                            <flux:checkbox wire:model="is_featured" label="{{ __('Featured') }}" />
                        </div>
                        <flux:input wire:model="image" type="file" label="{{ __('Image') }}" accept="image/*" />

                        <div class="flex justify-end gap-2 pt-4">
                            <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                            <flux:button variant="primary" type="submit">{{ __('Update Menu Item') }}</flux:button>
                        </div>
                    </form>
                </div>
            </flux:modal>

            {{-- Recipe Modal --}}
            <flux:modal wire:model.self="showRecipeModal" class="max-w-3xl">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Resep') }} — {{ $recipeMenuName }}</flux:heading>
                        <flux:subheading>{{ __('Komposisi bahan baku per 1 porsi. HPP dihitung otomatis.') }}</flux:subheading>
                    </div>

                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <div class="grid grid-cols-[1fr_140px_120px_auto] gap-3 items-end">
                            <flux:field>
                                <flux:label>{{ __('Bahan Baku') }}</flux:label>
                                <flux:select wire:model="newIngredientId" searchable :placeholder="__('Pilih bahan...')">
                                    <flux:select.option value="">{{ __('Pilih bahan...') }}</flux:select.option>
                                    @foreach ($this->ingredients as $ingredient)
                                        <flux:select.option value="{{ $ingredient->id }}">{{ $ingredient->name }} ({{ $ingredient->unit }})</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Jumlah / porsi') }}</flux:label>
                                <flux:input wire:model="newIngredientQty" type="number" min="1" />
                            </flux:field>
                            <flux:button variant="primary" wire:click="addRecipeLine">
                                {{ __('Tambah') }}
                            </flux:button>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <flux:table>
                            <flux:table.columns>
                                <flux:table.column>{{ __('Bahan') }}</flux:table.column>
                                <flux:table.column>{{ __('Jumlah / porsi') }}</flux:table.column>
                                <flux:table.column>{{ __('Cost Satuan') }}</flux:table.column>
                                <flux:table.column>{{ __('Subtotal') }}</flux:table.column>
                                <flux:table.column></flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @forelse ($recipeItems as $index => $line)
                                    <flux:table.row :key="'recipe-line-' . $index">
                                        <flux:table.cell>
                                            <div class="font-medium">{{ $line['name'] }}</div>
                                            <div class="text-xs text-zinc-500">{{ $line['unit'] }}</div>
                                        </flux:table.cell>
                                        <flux:table.cell class="w-32">
                                            <flux:input wire:model="recipeItems.{{ $index }}.quantity" type="number" min="1" size="xs" />
                                        </flux:table.cell>
                                        <flux:table.cell class="text-xs">{{ Number::currency($line['cost_minor'], 'IDR', 'id') }}</flux:table.cell>
                                        <flux:table.cell class="text-sm font-medium">{{ Number::currency($line['cost_minor'] * $line['quantity'], 'IDR', 'id') }}</flux:table.cell>
                                        <flux:table.cell>
                                            <flux:button variant="ghost" size="xs" variant="danger" wire:click="removeRecipeLine({{ $index }})">
                                                {{ __('Hapus') }}
                                            </flux:button>
                                        </flux:table.cell>
                                    </flux:table.row>
                                @empty
                                    <flux:table.row>
                                        <flux:table.cell colspan="5" class="text-center text-zinc-500 py-6">
                                            {{ __('Belum ada bahan dalam resep.') }}
                                        </flux:table.cell>
                                    </flux:table.row>
                                @endforelse
                            </flux:table.rows>
                        </flux:table>
                    </div>

                    <div class="flex items-center justify-between rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                        <span class="text-sm text-zinc-500">{{ __('Total HPP per porsi') }}</span>
                        <span class="text-lg font-semibold">{{ Number::currency($this->recipeTotalMinor, 'IDR', 'id') }}</span>
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                        <flux:button variant="primary" wire:click="saveRecipe">{{ __('Simpan Resep') }}</flux:button>
                    </div>
                </div>
            </flux:modal>
        </div>
    @endvolt
</x-layouts::app>