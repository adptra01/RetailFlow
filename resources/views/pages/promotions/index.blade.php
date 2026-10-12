<?php

use App\Models\Product;
use App\Models\Promotion;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\WithPagination;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\computed;
use function Livewire\Volt\state;
use function Livewire\Volt\uses;

name('promotions.index');
middleware('auth');
middleware('verified');
uses(WithPagination::class);

state(['search' => '', 'showCreateModal' => false, 'showEditModal' => false, 'editingPromotion' => null]);

state([
    'name' => '',
    'code' => '',
    'discount_type' => 'percent', // percent | nominal
    'discount_value' => 0,
    'scope' => 'transaction', // product | transaction
    'min_purchase_minor' => 0,
    'max_discount_minor' => null,
    'starts_at' => '',
    'ends_at' => '',
    'is_active' => true,
    'priority' => 0,
    'notes' => '',
    'product_search' => '',
    'selected_products' => [],
]);

$promotions = computed(function () {
    return Promotion::query()
        ->withCount('products')
        ->where(function ($q) {
            if ($this->search) {
                $q->where('name', 'like', '%' . $this->search . '%')
                  ->orWhere('code', 'like', '%' . $this->search . '%');
            }
        })
        ->orderBy('name')
        ->paginate(15);
});

$products = computed(function () {
    return Product::query()
        ->where(function ($q) {
            if ($this->product_search) {
                $q->where('name', 'like', '%' . $this->product_search . '%');
            }
        })
        ->orderBy('name')
        ->limit(100)
        ->get();
});

$openCreateModal = function () {
    $this->reset([
        'editingPromotion', 'name', 'code', 'discount_type', 'discount_value', 'scope',
        'min_purchase_minor', 'max_discount_minor', 'starts_at', 'ends_at', 'is_active',
        'priority', 'notes', 'product_search', 'selected_products',
    ]);
    $this->discount_type = 'percent';
    $this->discount_value = 10;
    $this->scope = 'transaction';
    $this->min_purchase_minor = 0;
    $this->is_active = true;
    $this->priority = 0;
    // reset() mengembalikan array state ke null (Livewire); tetapkan eksplisit.
    $this->selected_products = [];
    $this->showCreateModal = true;
};

$openEditModal = function (Promotion $promotion) {
    $this->editingPromotion = $promotion;
    $this->name = $promotion->name;
    $this->code = $promotion->code;
    $this->discount_type = $promotion->discount_type;
    $this->discount_value = $promotion->discount_value;
    $this->scope = $promotion->scope;
    $this->min_purchase_minor = $promotion->min_purchase_minor;
    $this->max_discount_minor = $promotion->max_discount_minor;
    $this->starts_at = $promotion->starts_at ? $promotion->starts_at->format('Y-m-d\TH:i') : '';
    $this->ends_at = $promotion->ends_at ? $promotion->ends_at->format('Y-m-d\TH:i') : '';
    $this->is_active = $promotion->is_active;
    $this->priority = $promotion->priority;
    $this->notes = $promotion->notes;
    $this->selected_products = $promotion->products->pluck('id')->all();
    $this->showEditModal = true;
};

$save = function () {
    $validated = $this->validate([
        'name' => 'required|string|max:200',
        'code' => 'nullable|string|max:50',
        'discount_type' => 'required|in:percent,nominal',
        'discount_value' => 'required|integer|min:1' . ($this->discount_type === 'percent' ? '|max:100' : ''),
        'scope' => 'required|in:product,transaction',
        'min_purchase_minor' => 'required|integer|min:0',
        'max_discount_minor' => 'nullable|integer|min:0',
        'starts_at' => 'nullable|date',
        'ends_at' => 'nullable|date|after_or_equal:starts_at',
        'is_active' => 'boolean',
        'priority' => 'required|integer|min:0',
        'notes' => 'nullable|string',
        'selected_products' => [Rule::requiredIf($this->scope === 'product'), 'array'],
        'selected_products.*' => 'integer|exists:products,id',
    ]);

    $promotion = $this->editingPromotion ?? new Promotion();
    $promotion->fill([
        // Sesi web tidak mengikat TenantContext; tenant diambil dari user aktif.
        'tenant_id' => auth()->user()?->tenant_id,
        'name' => $validated['name'],
        'code' => $validated['code'] ?? null,
        'discount_type' => $validated['discount_type'],
        'discount_value' => (int) $validated['discount_value'],
        'scope' => $validated['scope'],
        'min_purchase_minor' => (int) $validated['min_purchase_minor'],
        'max_discount_minor' => filled($validated['max_discount_minor']) ? (int) $validated['max_discount_minor'] : null,
        'starts_at' => $validated['starts_at'] ? Carbon::parse($validated['starts_at']) : null,
        'ends_at' => $validated['ends_at'] ? Carbon::parse($validated['ends_at']) : null,
        'is_active' => $this->is_active,
        'priority' => (int) $validated['priority'],
        'notes' => $validated['notes'] ?? null,
    ]);
    $promotion->save();

    // FR-06: promosi produk memegang daftar produknya; mengganti daftar
    // tidak pernah mengubah transaksi historis (snapshot sudah tersimpan).
    $promotion->products()->sync($promotion->isProductScoped() ? $this->selected_products : []);

    Flux::toast(variant: 'success', text: $this->editingPromotion ? __('Promotion updated.') : __('Promotion created.'));
    $this->showCreateModal = false;
    $this->showEditModal = false;
    $this->reset([
        'editingPromotion', 'name', 'code', 'discount_type', 'discount_value', 'scope',
        'min_purchase_minor', 'max_discount_minor', 'starts_at', 'ends_at', 'is_active',
        'priority', 'notes', 'product_search', 'selected_products',
    ]);
};

$toggleActive = function (Promotion $promotion) {
    $promotion->update(['is_active' => ! $promotion->is_active]);
};

$confirmDelete = function (Promotion $promotion) {
    // Snapshot (promotions_* pada transactions) dipertahankan via nullOnDelete.
    $promotion->delete();
    Flux::toast(variant: 'success', text: __('Promotion deleted.'));
};

?>

<x-layouts::app :title="__('Promotions')">
    @volt
        <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item href="{{ route('dashboard') }}">{{ __('Home') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ __('Promotions') }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>

            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="xl">{{ __('Promotions') }}</flux:heading>
                    <flux:subheading>{{ __('Diskon otomatis persen/nominal untuk produk atau seluruh transaksi (FR-06)') }}</flux:subheading>
                </div>
                <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
                    {{ __('Add Promotion') }}
                </flux:button>
            </div>

            <flux:input size="md" wire:model.live.debounce.300ms="search" type="search"
                placeholder="{{ __('Search promotions...') }}" />

            <flux:card class="!p-0">
                <flux:table :paginate="$this->promotions">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Name') }}</flux:table.column>
                        <flux:table.column>{{ __('Type') }}</flux:table.column>
                        <flux:table.column>{{ __('Scope') }}</flux:table.column>
                        <flux:table.column>{{ __('Value') }}</flux:table.column>
                        <flux:table.column>{{ __('Terms') }}</flux:table.column>
                        <flux:table.column>{{ __('Period') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column>{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($this->promotions as $promotion)
                            <flux:table.row :key="$promotion->id">
                                <flux:table.cell>
                                    <div class="font-medium">{{ $promotion->name }}</div>
                                    <div class="text-xs text-zinc-500">{{ $promotion->code ?? '' }}</div>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge :color="$promotion->isPercent() ? 'sky' : 'violet'" size="sm" inset="top bottom">
                                        {{ $promotion->isPercent() ? __('Percent') : __('Nominal') }}
                                    </flux:badge>
                                </flux:table.cell>
                                <flux:table.cell class="text-xs">
                                    {{ $promotion->isProductScoped() ? __('Per Produk') : __('Per Transaksi') }}
                                    @if ($promotion->isProductScoped())
                                        <span class="text-zinc-500">({{ $promotion->products_count }} {{ __('produk') }})</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="font-medium">{{ $promotion->label() }}</flux:table.cell>
                                <flux:table.cell class="text-xs text-zinc-500">
                                    @if ($promotion->min_purchase_minor > 0)
                                        <div>Min. {{ Number::currency($promotion->min_purchase_minor, 'IDR', 'id') }}</div>
                                    @endif
                                    @if ($promotion->max_discount_minor !== null)
                                        <div>Cap {{ Number::currency($promotion->max_discount_minor, 'IDR', 'id') }}</div>
                                    @endif
                                    @if ($promotion->min_purchase_minor === 0 && $promotion->max_discount_minor === null)
                                        <span class="text-zinc-400">-</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell class="text-xs">
                                    @if ($promotion->starts_at || $promotion->ends_at)
                                        {{ $promotion->starts_at?->format('d M Y') ?? '∞' }} – {{ $promotion->ends_at?->format('d M Y') ?? '∞' }}
                                    @else
                                        <span class="text-zinc-400">{{ __('Selalu') }}</span>
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge :color="$promotion->is_active ? 'green' : 'zinc'" size="sm" inset="top bottom">
                                        {{ $promotion->is_active ? __('Active') : __('Inactive') }}
                                    </flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:button size="xs" variant="ghost" wire:click="toggleActive({{ $promotion->id }})">
                                        {{ $promotion->is_active ? __('Nonaktifkan') : __('Aktifkan') }}
                                    </flux:button>
                                    <flux:button size="xs" variant="ghost" wire:click="openEditModal({{ $promotion->id }})">
                                        {{ __('Edit') }}
                                    </flux:button>
                                    <flux:button size="xs" variant="danger" wire:click="confirmDelete({{ $promotion->id }})"
                                        wire:confirm="{{ __('Hapus promosi ini? Transaksi historis tetap utuh.') }}">
                                        {{ __('Delete') }}
                                    </flux:button>
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="8" class="text-center text-zinc-500 py-6">
                                    {{ __('Belum ada promosi.') }}
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>

            <flux:modal name="create-promotion" :show="$showCreateModal" variant="flyout">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('New Promotion') }}</flux:heading>
                        <flux:subheading>{{ __('Diskon otomatis yang dievaluasi saat kasir.') }}</flux:subheading>
                    </div>

                    <flux:input wire:model="name" :label="__('Name')" placeholder="{{ __('Contoh: Diskon Akhir Pekan') }}" />
                    <div class="grid grid-cols-2 gap-4">
                        <flux:input wire:model="code" :label="__('Code')" placeholder="{{ __('Opsional, mis. WEEKEND10') }}" />
                        <flux:input wire:model="priority" type="number" inputmode="numeric" :label="__('Priority')" hint="{{ __('Tie-break diskon sama besar') }}" />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <flux:select wire:model="discount_type" :label="__('Type')">
                            <flux:select.option value="percent">{{ __('Percent') }}</flux:select.option>
                            <flux:select.option value="nominal">{{ __('Nominal (Rp)') }}</flux:select.option>
                        </flux:select>
                        <flux:input wire:model="discount_value" type="number" inputmode="numeric"
                            :label="__('Value')"
                            :placeholder="$discount_type === 'percent' ? '10' : '5000'"
                            hint="{{ $discount_type === 'percent' ? __('Persen dari subtotal') : __('Rupiah (nominal tetap)') }}" />
                    </div>
                    <flux:select wire:model="scope" :label="__('Scope')">
                        <flux:select.option value="transaction">{{ __('Seluruh transaksi') }}</flux:select.option>
                        <flux:select.option value="product">{{ __('Produk tertentu') }}</flux:select.option>
                    </flux:select>

                    @if ($scope === 'product')
                        <div class="space-y-2">
                            <flux:input size="sm" wire:model.live.debounce.300ms="product_search" type="search"
                                :label="__('Pilih Produk')" placeholder="{{ __('Cari produk...') }}" />
                            <flux:card class="!p-2 max-h-56 overflow-y-auto">
                                @forelse ($this->products as $product)
                                    <label class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">
                                        <flux:checkbox wire:model="selected_products" :value="$product->id" />
                                        <span class="flex-1">{{ $product->name }}</span>
                                        <span class="text-xs text-zinc-400">{{ Number::currency($product->price, 'IDR', 'id') }}</span>
                                    </label>
                                @empty
                                    <div class="p-3 text-sm text-zinc-500">{{ __('Tidak ada produk cocok.') }}</div>
                                @endforelse
                            </flux:card>
                        </div>
                    @endif

                    <div class="grid grid-cols-2 gap-4">
                        <flux:input wire:model="min_purchase_minor" type="number" inputmode="numeric" :label="__('Min Purchase (Rp)')" hint="{{ __('0 = tanpa syarat') }}" />
                        <flux:input wire:model="max_discount_minor" type="number" inputmode="numeric" :label="__('Max Discount (Rp)')" hint="{{ __('Opsional cap') }}" />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <flux:input type="datetime-local" wire:model="starts_at" :label="__('Start')" />
                        <flux:input type="datetime-local" wire:model="ends_at" :label="__('End')" />
                    </div>
                    <flux:switch wire:model="is_active" :label="__('Active')" />
                    <flux:textarea wire:model="notes" :label="__('Notes')" />

                    <div class="flex justify-end gap-2">
                        <flux:button variant="ghost" wire:click="$set('showCreateModal', false)">{{ __('Cancel') }}</flux:button>
                        <flux:button variant="primary" wire:click="save">{{ __('Save Promotion') }}</flux:button>
                    </div>
                </div>
            </flux:modal>

            <flux:modal name="edit-promotion" :show="$showEditModal" variant="flyout">
                <div class="space-y-6">
                    <div>
                        <flux:heading size="lg">{{ __('Edit Promotion') }}</flux:heading>
                        <flux:subheading>{{ __('Perbarui promosi. Transaksi historis tidak terpengaruh.') }}</flux:subheading>
                    </div>

                    <flux:input wire:model="name" :label="__('Name')" />
                    <div class="grid grid-cols-2 gap-4">
                        <flux:input wire:model="code" :label="__('Code')" placeholder="{{ __('Opsional') }}" />
                        <flux:input wire:model="priority" type="number" inputmode="numeric" :label="__('Priority')" />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <flux:select wire:model="discount_type" :label="__('Type')">
                            <flux:select.option value="percent">{{ __('Percent') }}</flux:select.option>
                            <flux:select.option value="nominal">{{ __('Nominal (Rp)') }}</flux:select.option>
                        </flux:select>
                        <flux:input wire:model="discount_value" type="number" inputmode="numeric" :label="__('Value')" />
                    </div>
                    <flux:select wire:model="scope" :label="__('Scope')">
                        <flux:select.option value="transaction">{{ __('Seluruh transaksi') }}</flux:select.option>
                        <flux:select.option value="product">{{ __('Produk tertentu') }}</flux:select.option>
                    </flux:select>

                    @if ($scope === 'product')
                        <div class="space-y-2">
                            <flux:input size="sm" wire:model.live.debounce.300ms="product_search" type="search"
                                :label="__('Pilih Produk')" placeholder="{{ __('Cari produk...') }}" />
                            <flux:card class="!p-2 max-h-56 overflow-y-auto">
                                @forelse ($this->products as $product)
                                    <label class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800">
                                        <flux:checkbox wire:model="selected_products" :value="$product->id" />
                                        <span class="flex-1">{{ $product->name }}</span>
                                        <span class="text-xs text-zinc-400">{{ Number::currency($product->price, 'IDR', 'id') }}</span>
                                    </label>
                                @empty
                                    <div class="p-3 text-sm text-zinc-500">{{ __('Tidak ada produk cocok.') }}</div>
                                @endforelse
                            </flux:card>
                        </div>
                    @endif

                    <div class="grid grid-cols-2 gap-4">
                        <flux:input wire:model="min_purchase_minor" type="number" inputmode="numeric" :label="__('Min Purchase (Rp)')" />
                        <flux:input wire:model="max_discount_minor" type="number" inputmode="numeric" :label="__('Max Discount (Rp)')" />
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <flux:input type="datetime-local" wire:model="starts_at" :label="__('Start')" />
                        <flux:input type="datetime-local" wire:model="ends_at" :label="__('End')" />
                    </div>
                    <flux:switch wire:model="is_active" :label="__('Active')" />
                    <flux:textarea wire:model="notes" :label="__('Notes')" />

                    <div class="flex justify-end gap-2">
                        <flux:button variant="ghost" wire:click="$set('showEditModal', false)">{{ __('Cancel') }}</flux:button>
                        <flux:button variant="primary" wire:click="save">{{ __('Save Promotion') }}</flux:button>
                    </div>
                </div>
            </flux:modal>
        </div>
    @endvolt
</x-layouts::app>