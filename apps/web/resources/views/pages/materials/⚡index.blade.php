<?php

use App\Library\Embeddings\MaterialSimilarity;
use App\Library\Previews\MaterialPreviews;
use App\Models\Category;
use App\Models\Material;
use App\Models\Supplier;
use App\Models\Variant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Livewire\Component;

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

    #[Url]
    public string $mode = 'keyword';

    #[Url(as: 'similar')]
    public string $similar = '';

    #[Url]
    public string $similarity = 'semantic';

    #[Url]
    public string $view = 'swatches';

    #[Url]
    public string $sort = 'name';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('materials.view'), 403);

        $this->mode = in_array($this->mode, ['keyword', 'semantic'], true) ? $this->mode : 'keyword';
        $this->similarity = in_array($this->similarity, ['semantic', 'appearance'], true) ? $this->similarity : 'semantic';
        $this->view = in_array($this->view, ['swatches', 'table'], true) ? $this->view : 'swatches';
        $this->sort = in_array($this->sort, ['name', 'newest', 'variants'], true) ? $this->sort : 'name';
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedMode(): void
    {
        $this->mode = in_array($this->mode, ['keyword', 'semantic'], true) ? $this->mode : 'keyword';
        $this->similar = '';
        $this->resetPage();
    }

    public function clearSimilarity(): void
    {
        $this->similar = '';
        $this->similarity = 'semantic';
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'category', 'supplier', 'status', 'similar', 'similarity');
        $this->resetPage();
    }

    public function hasFilters(): bool
    {
        return $this->search !== '' || $this->category !== '' || $this->supplier !== '' || $this->status !== '' || $this->similar !== '';
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

    public function updatedSort(): void
    {
        $this->sort = in_array($this->sort, ['name', 'newest', 'variants'], true) ? $this->sort : 'name';
        $this->resetPage();
    }

    public function setView(string $view): void
    {
        $this->view = in_array($view, ['swatches', 'table'], true) ? $view : 'swatches';
        $this->resetPage();
    }

    #[Computed]
    public function materials(): LengthAwarePaginator
    {
        $query = Material::query()
            ->visibleTo(auth()->user())
            ->withCount('variants')
            ->when($this->category !== '', fn ($query) => $query->where('category_id', $this->category))
            ->when($this->supplier !== '', fn ($query) => $query->where('supplier_id', $this->supplier))
            ->when($this->status === '', fn ($query) => $query->where('status', '!=', 'archived'))
            ->when($this->status !== '' && $this->status !== 'all', fn ($query) => $query->where('status', $this->status));

        if ($this->similarVariant !== null && config('opal.embeddings.enabled')) {
            $query = app(MaterialSimilarity::class)->toAppearance($query, $this->similarVariant);
        } elseif ($this->similarMaterial !== null && config('opal.embeddings.enabled')) {
            $query = app(MaterialSimilarity::class)->toMaterial($query, $this->similarMaterial);
        } elseif ($this->mode === 'semantic' && trim($this->search) !== '' && config('opal.embeddings.enabled')) {
            $query = app(MaterialSimilarity::class)->toText($query, $this->search);
        } else {
            $query->search($this->search);

            match ($this->sort) {
                'newest' => $query->orderByDesc('created_at')->orderBy('name'),
                'variants' => $query->orderByDesc('variants_count')->orderBy('name'),
                default => $query->orderBy('name'),
            };
        }

        return $query
            ->with(['category', 'supplier', 'currentVersion'])
            ->paginate(24);
    }

    #[Computed]
    public function similarMaterial(): ?Material
    {
        if ($this->similar === '' || $this->similarity !== 'semantic') {
            return null;
        }

        $material = Material::resolveCode($this->similar);

        return $material !== null && $material->isVisibleTo(auth()->user()) ? $material : null;
    }

    #[Computed]
    public function similarVariant(): ?Variant
    {
        if ($this->similar === '' || $this->similarity !== 'appearance') {
            return null;
        }

        $variant = Variant::resolveCode($this->similar);

        return $variant !== null && $variant->material->isVisibleTo(auth()->user()) ? $variant : null;
    }

    /**
     * @return array<int, \App\Models\File>
     */
    #[Computed]
    public function previews(): array
    {
        return app(MaterialPreviews::class)->filesFor($this->materials->getCollection());
    }

    /**
     * @return array<int, Collection<int, \App\Models\Variant>>
     */
    #[Computed]
    public function chips(): array
    {
        if ($this->similarVariant !== null) {
            $matchedIds = $this->materials->getCollection()
                ->pluck('matched_variant_id')
                ->filter()
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            return Variant::query()
                ->whereIn('id', $matchedIds)
                ->get()
                ->groupBy('material_id')
                ->map(fn (Collection $variants): Collection => $variants->values())
                ->all();
        }

        return app(MaterialPreviews::class)->chipsFor($this->materials->getCollection());
    }

    /**
     * @return array<int, \App\Models\File>
     */
    #[Computed]
    public function variantFiles(): array
    {
        return app(MaterialPreviews::class)->variantFilesFor(collect($this->chips)->flatten(1));
    }

    /**
     * @return array<int, array<string, string>>
     */
    #[Computed]
    public function targets(): array
    {
        return app(MaterialPreviews::class)->targetsFor($this->materials->getCollection());
    }

    /**
     * @return array{variants: list<array{id: int, code: string, name: string, hex: string, image: string|null}>, active: int}
     */
    public function cardData(Material $material): array
    {
        return app(MaterialPreviews::class)->cardData(
            $material,
            $this->chips[$material->id] ?? collect(),
            $this->variantFiles,
            $this->previews[$material->id] ?? null,
        );
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

<section class="ui-library" x-data="{ filtersOpen: @js($supplier !== '' || $status !== '') }">
    <div class="ui-page-head ui-library-head">
        <div>
            <x-ui.eyebrow>{{ __('Workspace library') }}</x-ui.eyebrow>
            <h1>{{ __('Materials') }}<span class="ui-library-head__dot">.</span></h1>
            <p class="ui-page-head__lede">{{ __('Find a finish. Explore the details. Make it yours.') }}</p>
        </div>
        <div class="ui-page-head__actions">
            @can('materials.contribute')
                <x-ui.button :href="route('materials.studio')" variant="secondary" data-test="material-studio" wire:navigate>{{ __('Material Studio') }}</x-ui.button>
                <x-ui.button :href="route('materials.create')" data-test="add-material" wire:navigate>
                    {{ __('Import maps') }}
                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 4v12M4 10h12" /></svg>
                </x-ui.button>
            @endcan
        </div>
    </div>

    <div class="ui-library-searchbar">
        <label class="ui-search">
            <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="9" cy="9" r="5.5" /><path d="m13.5 13.5 3 3" /></svg>
            <input class="ui-input" type="search" wire:model.live.debounce.350ms="search" placeholder="{{ $mode === 'semantic' ? __('Describe a colour, texture or feeling…') : __('Search materials, colours, suppliers…') }}" aria-label="{{ __('Search materials') }}" data-test="search" />
        </label>
        <select class="ui-select" wire:model.live="category" aria-label="{{ __('Category') }}">
            <option value="">{{ __('All categories') }}</option>
            @foreach ($this->categories as $category)
                <option value="{{ $category->id }}">{{ $category->name }}</option>
            @endforeach
        </select>
        <button type="button" class="ui-library-filter-button" x-on:click="filtersOpen = ! filtersOpen" x-bind:aria-expanded="filtersOpen" aria-controls="library-filters">
            <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 6h14M3 14h14"/><circle cx="7" cy="6" r="2"/><circle cx="13" cy="14" r="2"/></svg>
            {{ __('Filters') }}@if ($supplier !== '' || $status !== '')<span class="ui-filter-dot"></span>@endif
        </button>
    </div>
    <div class="ui-library-filters" id="library-filters" x-show="filtersOpen" x-cloak>
        <select class="ui-select" wire:model.live="supplier" aria-label="{{ __('Supplier') }}">
            <option value="">{{ __('All suppliers') }}</option>
            @foreach ($this->suppliers as $supplier)
                <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
            @endforeach
        </select>
        <select class="ui-select" wire:model.live="status" aria-label="{{ __('Status') }}">
            <option value="">{{ __('Current (active + draft)') }}</option>
            <option value="all">{{ __('All statuses') }}</option>
            <option value="active">{{ __('Active') }}</option>
            <option value="draft">{{ __('Draft') }}</option>
            <option value="archived">{{ __('Archived') }}</option>
        </select>
        @if (config('opal.embeddings.enabled'))
            <div class="ui-segment" role="group" aria-label="{{ __('Search mode') }}">
                <button type="button" wire:click="$set('mode', 'keyword')" @class(['is-active' => $mode === 'keyword' && $similar === '']) data-test="search-keyword">{{ __('Keywords') }}</button>
                <button type="button" wire:click="$set('mode', 'semantic')" @class(['is-active' => $mode === 'semantic' && $similar === '']) data-test="search-semantic">{{ __('Meaning') }}</button>
            </div>
        @endif
    </div>
    <div class="ui-library-results">
        <div class="ui-library-results__count" aria-live="polite">
            <strong>{{ number_format($this->materials->total()) }}</strong> {{ __('materials') }}
            @if ($this->hasFilters())
                <button type="button" wire:click="clearFilters" data-test="clear-filters">{{ __('Clear filters') }} <span aria-hidden="true">×</span></button>
            @endif
        </div>
        <div class="ui-library-results__controls">
        @if ($similar === '' && ! ($mode === 'semantic' && trim($search) !== ''))
            <select class="ui-select" wire:model.live="sort" aria-label="{{ __('Sort materials') }}">
                <option value="name">{{ __('Name A–Z') }}</option>
                <option value="newest">{{ __('Newest first') }}</option>
                <option value="variants">{{ __('Most variants') }}</option>
            </select>
        @endif
        <div class="ui-segment" role="group" aria-label="{{ __('View') }}">
            <button type="button" wire:click="setView('swatches')" @class(['is-active' => $view === 'swatches']) aria-pressed="{{ $view === 'swatches' ? 'true' : 'false' }}" data-test="view-swatches">
                <svg viewBox="0 0 20 20" aria-hidden="true"><rect x="3" y="3" width="6" height="6" rx="1" /><rect x="11" y="3" width="6" height="6" rx="1" /><rect x="3" y="11" width="6" height="6" rx="1" /><rect x="11" y="11" width="6" height="6" rx="1" /></svg>
                {{ __('Grid') }}
            </button>
            <button type="button" wire:click="setView('table')" @class(['is-active' => $view === 'table']) aria-pressed="{{ $view === 'table' ? 'true' : 'false' }}" data-test="view-table">
                <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 5h14M3 10h14M3 15h14" /></svg>
                {{ __('List') }}
            </button>
        </div>
        </div>
    </div>
    <div class="ui-library-progress" role="status" aria-live="polite">
        <span wire:loading.delay wire:target="search,category,supplier,status,sort,mode,setView,clearFilters,clearSimilarity">{{ __('Updating materials…') }}</span>
    </div>

    @if ($this->similarVariant || $this->similarMaterial)
        <x-ui.panel tone="paper" style="margin-bottom: 12px" data-test="similar-source">
            <div class="ui-panel__heading">
                <div>
                    @if ($this->similarVariant)
                        <h3>{{ __('Looks like :material · :variant', ['material' => $this->similarVariant->material->name, 'variant' => $this->similarVariant->name]) }}</h3>
                        <p>{{ __('Ranked only from rendered appearance. Each result shows the closest matching colourway; unrelated variants are excluded.') }}</p>
                    @else
                        <h3>{{ __('Same type as :name', ['name' => $this->similarMaterial->name]) }}</h3>
                        <p>{{ __('Ranked from material type, use, installation and specifications—not visual appearance. Your filters still apply.') }}</p>
                    @endif
                </div>
                <x-ui.button wire:click="clearSimilarity" variant="quiet" size="sm">{{ __('Clear similarity') }}</x-ui.button>
            </div>
        </x-ui.panel>
    @endif

    @if ($this->materials->isEmpty())
        <x-ui.panel data-test="empty">
            <x-ui.empty-state :title="__('No materials match')" :description="__('Try another term, or add the material if it is missing.')" />
        </x-ui.panel>
    @elseif ($view === 'table')
        <div class="ui-data-shell">
            <div class="ui-table-scroll">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th>{{ __('Material') }}</th>
                            <th>{{ __('Code') }}</th>
                            <th>{{ __('Category') }}</th>
                            <th>{{ __('Supplier') }}</th>
                            <th>{{ __('Variants') }}</th>
                            <th>{{ __('Version') }}</th>
                            <th>{{ __('Status') }}</th>
                            @if ($similar !== '' || $mode === 'semantic')<th>{{ __('Match') }}</th>@endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->materials as $material)
                            @php
                                $matchedVariantId = (int) $material->getAttribute('matched_variant_id');
                                $preview = $matchedVariantId > 0
                                    ? ($this->variantFiles[$matchedVariantId] ?? $this->previews[$material->id] ?? null)
                                    : ($this->previews[$material->id] ?? null);
                                $chips = $this->chips[$material->id] ?? collect();
                            @endphp
                            <tr wire:key="row-{{ $material->id }}" data-test="material-row">
                                <td>
                                    <a class="ui-table__material" href="{{ route('materials.show', $material) }}" x-data="quickLink" x-on:click="quickOpen($event, @js(['code' => $material->code, 'name' => $material->name, 'category' => $material->category->name]))" style="text-decoration: none">
                                        @if ($preview)
                                            <img class="ui-table__swatch" src="{{ $preview->previewUrl(96) }}" alt="" loading="lazy" decoding="async" width="40" height="40" style="object-fit: cover" />
                                        @else
                                            <span class="ui-table__swatch" style="background: {{ $chips->first()?->dominant_hex ?? \App\Library\Previews\MaterialPreviews::fallbackHex($material->code) }}"></span>
                                        @endif
                                        <div>
                                            <strong>{{ $material->name }}</strong>
                                            <small>{{ $material->supplier_product_code ?? $material->collection ?? '—' }}</small>
                                        </div>
                                    </a>
                                </td>
                                <td><span class="ui-code">{{ $material->code }}</span></td>
                                <td>{{ $material->category->name }}</td>
                                <td>{{ $material->supplier?->name ?? __('In-house') }}</td>
                                <td>{{ $material->variants_count }}</td>
                                <td>{{ $material->currentVersion ? 'v'.$material->currentVersion->number : '—' }}</td>
                                <td>
                                    <x-ui.badge :tone="match ($material->status->value) { 'active' => 'success', 'archived' => 'neutral', default => 'warning' }" dot>{{ $material->status->label() }}</x-ui.badge>
                                    @if ($material->visibility->value === 'restricted')
                                        <x-ui.badge tone="warning">{{ __('Restricted') }}</x-ui.badge>
                                    @endif
                                </td>
                                @if ($similar !== '' || $mode === 'semantic')
                                    <td>{{ $material->getAttribute('similarity_score') !== null ? round(max(0, min(1, (float) $material->getAttribute('similarity_score'))) * 100).'%' : '—' }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="ui-swatch-grid" data-test="swatch-grid" wire:loading.class.delay="is-updating" wire:target="search,category,supplier,status,sort,mode,setView,clearFilters,clearSimilarity">
            @foreach ($this->materials as $material)
                @php
                    $card = $this->cardData($material);
                    $first = $card['variants'][$card['active']];
                    $targets = $this->targets[$material->id] ?? [];
                @endphp
                <article
                    class="ui-swatch-card"
                    wire:key="card-{{ $material->id }}"
                    data-test="material-card"
                    x-data="swatchCard(@js($card))"
                    x-on:mouseenter="enter"
                    x-on:mouseleave="leave"
                    x-bind:class="hovering && 'is-hovering'"
                >
                    <x-ui.swatch-preview :card="$card" :targets="$targets" :tag="$material->category->code" :variants-count="$material->variants_count" :name="$material->name" :eager="$loop->index < 4" />
                    <div class="ui-swatch-card__body">
                        <strong><a class="ui-library-card-link" href="{{ route('materials.show', $material) }}" x-on:click="quickOpen($event, @js(['code' => $material->code, 'name' => $material->name, 'category' => $material->category->name]))">{{ $material->name }}</a></strong>
                        <small>{{ $material->supplier?->name ?? __('In-house') }}@if ($material->collection) · {{ $material->collection }}@endif</small>
                        <div class="ui-swatch-card__meta">
                            <span>{{ trans_choice(':count variant|:count variants', $material->variants_count) }}</span>
                            <span>
                                @if ($material->getAttribute('similarity_score') !== null)
                                    {{ round(max(0, min(1, (float) $material->getAttribute('similarity_score'))) * 100) }}% {{ __('match') }}
                                @else
                                    {{ $material->currentVersion ? 'v'.$material->currentVersion->number : $material->status->label() }}
                                @endif
                            </span>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    {{ $this->materials->links('vendor.pagination.opal') }}

    <livewire:materials.quick-view :key="'material-quick-view'" />
</section>
