<?php

use App\Models\Category;
use Flux\Flux;
use Livewire\WithPagination;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

name('categories.index');

middleware('auth');
middleware('verified');

uses(WithPagination::class);

state([
    'search' => '',
    'sortBy' => 'id',
    'sortDirection' => 'asc',
])->url();

state([
    'showCreateModal' => false,
    'showEditModal' => false,
    'showDeleteModal' => false,
    'editingCategoryId' => null,
    'deletingCategoryId' => null,
    'name' => '',
    'slug' => '',
    'description' => '',
]);

$sort = function ($column) {
    if ($this->sortBy === $column) {
        $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
    } else {
        $this->sortBy = $column;
        $this->sortDirection = 'asc';
    }
};

$categories = computed(function () {
    return Category::query()
        ->where('name', 'like', '%' . $this->search . '%')
        ->orderBy($this->sortBy, $this->sortDirection)
        ->paginate(10);
});

$updatedName = function () {
    if (empty($this->slug) || $this->slug === str()->slug($this->name)) {
        $this->slug = str()->slug($this->name);
    }
};

$create = function () {
    $this->reset(['name', 'slug', 'description', 'editingCategoryId', 'deletingCategoryId']);
    $this->showCreateModal = true;
};

$save = function () {
    if (empty($this->slug)) {
        $this->slug = str()->slug($this->name);
    }

    $validated = $this->validate([
        'name' => 'required|string|max:100',
        'slug' => 'required|string|max:120|unique:categories,slug',
        'description' => 'nullable|string',
    ]);

    Category::create($validated);

    $this->reset(['name', 'slug', 'description', 'showCreateModal']);

    Flux::toast(variant: 'success', text: __('Category created.'));
};

$edit = function ($id) {
    $category = Category::findOrFail($id);

    $this->editingCategoryId = $category->id;
    $this->name = $category->name;
    $this->slug = $category->slug;
    $this->description = $category->description ?? '';
    $this->showEditModal = true;
};

$update = function () {
    $category = Category::findOrFail($this->editingCategoryId);

    if (empty($this->slug)) {
        $this->slug = str()->slug($this->name);
    }

    $validated = $this->validate([
        'name' => 'required|string|max:100',
        'slug' => 'required|string|max:120|unique:categories,slug,' . $this->editingCategoryId,
        'description' => 'nullable|string',
    ]);

    $category->update($validated);

    $this->reset(['name', 'slug', 'description', 'editingCategoryId', 'showEditModal']);

    Flux::toast(variant: 'success', text: __('Category updated.'));
};

$confirmDelete = function ($id) {
    $this->deletingCategoryId = $id;
    $this->showDeleteModal = true;
};

$delete = function () {
    $category = Category::findOrFail($this->deletingCategoryId);
    $category->delete();

    $this->deletingCategoryId = null;
    $this->showDeleteModal = false;

    Flux::toast(variant: 'success', text: __('Category deleted.'));
};

?>

<x-layouts::app :title="__('Categories')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-4 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Categories') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex items-center justify-between">
                <flux:heading size="lg">{{ __('Categories') }}</flux:heading>
                <flux:button variant="primary" icon="plus" wire:click="create">
                    {{ __('Add') }}
                </flux:button>
            </div>

            <flux:input size="md" wire:model.live="search" type="search" placeholder="{{ __('Filter by name...') }}" />

            <div
                class="relative h-full flex-1 overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700 p-6">
                
                <flux:table :paginate="$this->categories">
                    <flux:table.columns>
                        <flux:table.column sortable :sorted="$sortBy === 'name'" :direction="$sortDirection"
                            wire:click="sort('name')">
                            {{ __('Name') }}
                        </flux:table.column>

                        <flux:table.column sortable :sorted="$sortBy === 'slug'" :direction="$sortDirection"
                            wire:click="sort('slug')">
                            {{ __('Slug') }}
                        </flux:table.column>

                        <flux:table.column>
                            {{ __('Description') }}
                        </flux:table.column>

                        <flux:table.column>
                            {{ __('Actions') }}
                        </flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->categories as $category)
                            <flux:table.row :key="$category->id">
                                <flux:table.cell>{{ $category->name }}</flux:table.cell>

                                <flux:table.cell>
                                    <flux:badge size="sm" inset="top bottom">
                                        {{ $category->slug }}
                                    </flux:badge>
                                </flux:table.cell>

                                <flux:table.cell variant="strong">{{ $category->description }}</flux:table.cell>

                                <flux:table.cell>
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal"
                                            inset="top bottom" />
                                        <flux:menu>
                                            <flux:menu.item icon="pencil" wire:click="edit({{ $category->id }})">
                                                {{ __('Edit') }}
                                            </flux:menu.item>
                                            <flux:menu.separator />
                                            <flux:menu.item icon="trash" variant="danger"
                                                wire:click="confirmDelete({{ $category->id }})">
                                                {{ __('Delete') }}
                                            </flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>

            </div>


            {{-- Create Modal --}}
            <flux:modal wire:model.self="showCreateModal" class="max-w-4xl w-full">
                <form wire:submit="save" class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Create Category') }}</flux:heading>
                        <flux:subheading>{{ __('Add a new product category.') }}</flux:subheading>
                    </div>

                    <flux:input wire:model="name" :label="__('Name')" required autofocus />

                    <flux:input wire:model="slug" :label="__('Slug')" />

                    <flux:textarea wire:model="description" :label="__('Description')" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                    </div>
                </form>
            </flux:modal>

            {{-- Edit Modal --}}
            <flux:modal wire:model.self="showEditModal" class="max-w-4xl w-full">
                <form wire:submit="update" class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Edit Category') }}</flux:heading>
                        <flux:subheading>{{ __('Update category details.') }}</flux:subheading>
                    </div>

                    <flux:input wire:model="name" :label="__('Name')" required autofocus />

                    <flux:input wire:model="slug" :label="__('Slug')" />

                    <flux:textarea wire:model="description" :label="__('Description')" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="primary" type="submit">{{ __('Update') }}</flux:button>
                    </div>
                </form>
            </flux:modal>

            {{-- Delete Confirmation Modal --}}
            <flux:modal wire:model.self="showDeleteModal" class="max-w-lg">
                <form wire:submit="delete" class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Delete Category') }}</flux:heading>
                        <flux:subheading>
                            {{ __('Are you sure you want to delete this category? Products in this category will not be deleted. This action cannot be undone.') }}
                        </flux:subheading>
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="danger" type="submit">{{ __('Delete') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        </div>
    @endvolt
</x-layouts::app>
