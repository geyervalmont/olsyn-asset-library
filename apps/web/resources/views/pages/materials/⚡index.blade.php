<?php

use App\Models\Category;
use App\Models\Material;
use App\Models\Supplier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Library')] class extends Component {
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $supplier = '';

    #[Url]
    public string $status = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('materials.view'), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    public function updatedSupplier(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function materials(): LengthAwarePaginator
    {
        return Material::query()
            ->visibleTo(auth()->user())
            ->search($this->search)
            ->when($this->category !== '', fn ($query) => $query->where('category_id', $this->category))
            ->when($this->supplier !== '', fn ($query) => $query->where('supplier_id', $this->supplier))
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->with(['category', 'supplier', 'currentVersion'])
            ->withCount('variants')
            ->orderBy('name')
            ->paginate(25);
    }

    #[Computed]
    public function categories(): Collection
    {
        return Category::query()->orderBy('sort_order')->orderBy('name')->get();
    }

    #[Computed]
    public function suppliers(): Collection
    {
        return Supplier::query()->orderBy('name')->get();
    }
}; ?>

<section class="w-full">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ __('Library') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Search by name, code, supplier, product code, colourway or tag.') }}</flux:text>
        </div>
        @can('materials.contribute')
            <flux:button :href="route('materials.create')" variant="primary" icon="plus" wire:navigate data-test="add-material">
                {{ __('Add material') }}
            </flux:button>
        @endcan
    </div>

    <div class="mt-6 grid gap-3 md:grid-cols-4">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search materials…')" class="md:col-span-2" data-test="search" />
        <flux:select wire:model.live="category" :placeholder="__('All categories')">
            <flux:select.option value="">{{ __('All categories') }}</flux:select.option>
            @foreach ($this->categories as $category)
                <flux:select.option value="{{ $category->id }}">{{ $category->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="supplier" :placeholder="__('All suppliers')">
            <flux:select.option value="">{{ __('All suppliers') }}</flux:select.option>
            @foreach ($this->suppliers as $supplier)
                <flux:select.option value="{{ $supplier->id }}">{{ $supplier->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="mt-6">
        @if ($this->materials->isEmpty())
            <div class="rounded-xl border border-dashed border-neutral-300 p-10 text-center dark:border-neutral-700" data-test="empty">
                <flux:heading size="lg">{{ __('No materials match') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Try another term, or add the material if it is missing.') }}</flux:text>
            </div>
        @else
            <flux:table :paginate="$this->materials">
                <flux:table.columns>
                    <flux:table.column>{{ __('Code') }}</flux:table.column>
                    <flux:table.column>{{ __('Material') }}</flux:table.column>
                    <flux:table.column>{{ __('Category') }}</flux:table.column>
                    <flux:table.column>{{ __('Supplier') }}</flux:table.column>
                    <flux:table.column>{{ __('Variants') }}</flux:table.column>
                    <flux:table.column>{{ __('Version') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->materials as $material)
                        <flux:table.row :key="$material->id" data-test="material-row">
                            <flux:table.cell><code class="text-xs">{{ $material->code }}</code></flux:table.cell>
                            <flux:table.cell variant="strong">
                                <flux:link :href="route('materials.show', $material)" wire:navigate>{{ $material->name }}</flux:link>
                            </flux:table.cell>
                            <flux:table.cell>{{ $material->category->name }}</flux:table.cell>
                            <flux:table.cell>{{ $material->supplier?->name ?? __('In-house') }}</flux:table.cell>
                            <flux:table.cell>{{ $material->variants_count }}</flux:table.cell>
                            <flux:table.cell>{{ $material->currentVersion?->number !== null ? 'v'.$material->currentVersion->number : '—' }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:badge size="sm" :color="match ($material->status->value) { 'active' => 'lime', 'archived' => 'zinc', default => 'amber' }">{{ $material->status->label() }}</flux:badge>
                                @if ($material->visibility->value === 'restricted')
                                    <flux:badge size="sm" color="amber" icon="lock-closed">{{ __('Restricted') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </div>
</section>
