<?php

use App\Actions\Clients\IssueClientCommand;
use App\Enums\CommandType;
use App\Library\Embeddings\MaterialSimilarity;
use App\Library\Previews\MaterialPreviews;
use App\Models\Category;
use App\Models\ClientCommand;
use App\Models\ClientSession;
use App\Models\Material;
use App\Models\Supplier;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
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

    #[Url]
    public string $mode = 'keyword';

    #[Url(as: 'similar')]
    public string $similar = '';

    #[Url]
    public string $view = 'swatches';

    #[Url]
    public string $sort = 'name';

    /** Material code shown in the quick view; empty when the modal is closed. */
    #[Url(as: 'material')]
    public string $quick = '';

    public int $userId = 0;

    public ?int $revitSessionId = null;

    public ?int $revitCommandId = null;

    /** The colourway the card was showing when it was opened. */
    public ?int $quickVariantId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('materials.view'), 403);

        $this->mode = in_array($this->mode, ['keyword', 'semantic'], true) ? $this->mode : 'keyword';
        $this->view = in_array($this->view, ['swatches', 'table'], true) ? $this->view : 'swatches';
        $this->sort = in_array($this->sort, ['name', 'newest', 'variants'], true) ? $this->sort : 'name';
        $this->userId = (int) auth()->id();
        $this->revitSessionId = $this->revitSessions->first()?->id;
    }

    public function openQuick(string $code, ?int $variantId = null): void
    {
        $this->quick = $code;
        $this->quickVariantId = $variantId;
        $this->revitCommandId = null;
        unset($this->quickMaterial, $this->quickCard, $this->quickTargets, $this->quickViewerSets, $this->revitCommand);
    }

    public function closeQuick(): void
    {
        $this->quick = '';
        $this->quickVariantId = null;
        $this->revitCommandId = null;
        unset($this->quickMaterial, $this->quickCard, $this->quickTargets, $this->quickViewerSets, $this->revitCommand);
    }

    /** The material behind the quick view, or null when it is closed or out of reach. */
    #[Computed]
    public function quickMaterial(): ?Material
    {
        if ($this->quick === '') {
            return null;
        }

        $material = Material::resolveCode($this->quick);

        return $material !== null && $material->isVisibleTo(auth()->user())
            ? $material->load(['category', 'supplier', 'currentVersion'])->loadCount('variants')
            : null;
    }

    /**
     * @return array{variants: list<array{id: int, code: string, name: string, hex: string, image: string|null}>, active: int}
     */
    #[Computed]
    public function quickCard(): array
    {
        $material = $this->quickMaterial;
        $previews = app(MaterialPreviews::class);
        $collection = $material->newCollection([$material]);
        $chips = $previews->chipsFor($collection, 24)[$material->id] ?? collect();
        $card = $previews->cardData($material, $chips, $previews->variantFilesFor($chips), $previews->filesFor($collection)[$material->id] ?? null);

        // Open on the colourway the card was showing, when it is among the chips.
        $chosen = array_search($this->quickVariantId, array_column($card['variants'], 'id'), true);

        if ($chosen !== false) {
            $card['active'] = $chosen;
        }

        return $card;
    }

    /**
     * Canonical maps for the open material, for the shared inspector.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function quickViewerSets(): array
    {
        $material = $this->quickMaterial;
        $variants = $material
            ->variants()
            ->with(['representations.target', 'representations.quality', 'representations.representationFiles.role', 'representations.representationFiles.file'])
            ->get();

        return app(MaterialPreviews::class)->viewerSets($material, $variants);
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function quickTargets(): array
    {
        $material = $this->quickMaterial;

        return app(MaterialPreviews::class)->targetsFor($material->newCollection([$material]))[$material->id] ?? [];
    }

    /**
     * Why the quick view cannot apply right now, or null when it can.
     */
    public function applyBlockedReason(): ?string
    {
        if ($this->revitSessions->isEmpty()) {
            return __('No Revit connected. Open OPAL → Connect in Revit.');
        }

        return $this->quickMaterial?->current_version_id === null
            ? __('Publish a version first; nothing is on the drive yet.')
            : null;
    }

    /**
     * @return Collection<int, ClientSession>
     */
    #[Computed]
    public function revitSessions(): Collection
    {
        return ClientSession::query()->where('user_id', $this->userId)->live()->orderByDesc('last_seen_at')->get();
    }

    #[Computed]
    public function revitCommand(): ?ClientCommand
    {
        return $this->revitCommandId === null
            ? null
            : ClientCommand::query()->with('session')->whereKey($this->revitCommandId)->where('issued_by', $this->userId)->first();
    }

    public function applyInRevit(int $variantId, IssueClientCommand $issue): void
    {
        $material = $this->quickMaterial;
        $variant = $material?->variants()->whereKey($variantId)->first();
        $session = $this->revitSessions->firstWhere('id', $this->revitSessionId) ?? $this->revitSessions->first();

        if ($material === null || $variant === null || $session === null || $this->applyBlockedReason() !== null) {
            Flux::toast(variant: 'warning', text: $this->applyBlockedReason() ?? __('That colourway is no longer available.'));

            return;
        }

        $this->revitSessionId = $session->id;
        $this->revitCommandId = $issue->handle($session, auth()->user(), CommandType::Apply, [
            'variant' => $variant->code,
            'material' => $material->code,
        ])->id;

        unset($this->revitCommand);
    }

    #[On('echo-private:user.{userId},.command.acked')]
    #[On('echo-private:user.{userId},.command.completed')]
    public function revitCommandUpdated(): void
    {
        unset($this->revitCommand);
    }

    #[On('echo-private:user.{userId},.session.updated')]
    public function revitSessionsUpdated(): void
    {
        unset($this->revitSessions);

        if ($this->revitSessionId === null || ! $this->revitSessions->contains('id', $this->revitSessionId)) {
            $this->revitSessionId = $this->revitSessions->first()?->id;
        }
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
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'category', 'supplier', 'status', 'similar');
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

    public function setView(string $view): void
    {
        $this->view = in_array($view, ['swatches', 'table'], true) ? $view : 'swatches';
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

        if ($this->similarMaterial !== null && config('opal.embeddings.enabled')) {
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
            ->paginate($this->view === 'table' ? 25 : 30);
    }

    #[Computed]
    public function similarMaterial(): ?Material
    {
        if ($this->similar === '') {
            return null;
        }

        $material = Material::resolveCode($this->similar);

        return $material !== null && $material->isVisibleTo(auth()->user()) ? $material : null;
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

<section>
    <div class="ui-page-head">
        <div>
            <x-ui.eyebrow>{{ __('Library') }}</x-ui.eyebrow>
            <h1>{{ __('Materials') }} <small>{{ number_format($this->materials->total()) }} {{ __('records') }}</small></h1>
            <p class="ui-page-head__lede">{{ __('Find a known specification with keywords, discover related finishes by meaning, or start a traceable import or procedural recipe.') }}</p>
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

    <div class="ui-toolbar">
        <label class="ui-search">
            <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="9" cy="9" r="5.5" /><path d="m13.5 13.5 3 3" /></svg>
            <input class="ui-input" type="search" wire:model.live.debounce.600ms="search" placeholder="{{ $mode === 'semantic' ? __('Describe colour, texture or use…') : __('Name, code, supplier or colour…') }}" aria-label="{{ __('Search materials') }}" data-test="search" />
        </label>
        @if (config('opal.embeddings.enabled'))
            <div class="ui-segment" role="group" aria-label="{{ __('Search mode') }}">
                <button type="button" wire:click="$set('mode', 'keyword')" @class(['is-active' => $mode === 'keyword' && $similar === '']) data-test="search-keyword">{{ __('Keywords') }}</button>
                <button type="button" wire:click="$set('mode', 'semantic')" @class(['is-active' => $mode === 'semantic' && $similar === '']) data-test="search-semantic">{{ __('Meaning') }}</button>
            </div>
        @endif
        <select class="ui-select" wire:model.live="category" aria-label="{{ __('Category') }}">
            <option value="">{{ __('All categories') }}</option>
            @foreach ($this->categories as $category)
                <option value="{{ $category->id }}">{{ $category->name }}</option>
            @endforeach
        </select>
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
        @if ($similar === '' && ! ($mode === 'semantic' && trim($search) !== ''))
            <select class="ui-select" wire:model.live="sort" aria-label="{{ __('Sort materials') }}">
                <option value="name">{{ __('Name A–Z') }}</option>
                <option value="newest">{{ __('Newest first') }}</option>
                <option value="variants">{{ __('Most variants') }}</option>
            </select>
        @endif
        <div class="ui-segment" role="group" aria-label="{{ __('View') }}">
            <button type="button" wire:click="setView('swatches')" @class(['is-active' => $view === 'swatches']) data-test="view-swatches">
                <svg viewBox="0 0 20 20" aria-hidden="true"><rect x="3" y="3" width="6" height="6" rx="1" /><rect x="11" y="3" width="6" height="6" rx="1" /><rect x="3" y="11" width="6" height="6" rx="1" /><rect x="11" y="11" width="6" height="6" rx="1" /></svg>
                {{ __('Swatches') }}
            </button>
            <button type="button" wire:click="setView('table')" @class(['is-active' => $view === 'table']) data-test="view-table">
                <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 5h14M3 10h14M3 15h14" /></svg>
                {{ __('Table') }}
            </button>
        </div>
        @if ($this->hasFilters())
            <x-ui.button wire:click="clearFilters" variant="ghost" size="sm" data-test="clear-filters">{{ __('Clear') }}</x-ui.button>
        @endif
    </div>

    @if ($this->similarMaterial)
        <x-ui.panel tone="paper" style="margin-bottom: 12px" data-test="similar-source">
            <div class="ui-panel__heading">
                <div>
                    <h3>{{ __('Materials similar to :name', ['name' => $this->similarMaterial->name]) }}</h3>
                    <p>{{ __('Ranked from the material description and rendered appearance. Your category, supplier and status filters still apply.') }}</p>
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
                            @php $preview = $this->previews[$material->id] ?? null; $chips = $this->chips[$material->id] ?? collect(); @endphp
                            <tr wire:key="row-{{ $material->id }}" data-test="material-row">
                                <td>
                                    <a class="ui-table__material" href="{{ route('materials.show', $material) }}" x-data="quickLink" x-on:click="quickOpen($event, @js($material->code))" style="text-decoration: none">
                                        @if ($preview)
                                            <img class="ui-table__swatch" src="{{ $preview->url() }}" alt="" loading="lazy" style="object-fit: cover" />
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
        <div class="ui-swatch-grid" data-test="swatch-grid">
            @foreach ($this->materials as $material)
                @php
                    $card = $this->cardData($material);
                    $first = $card['variants'][$card['active']];
                    $targets = $this->targets[$material->id] ?? [];
                @endphp
                <a
                    class="ui-swatch-card"
                    href="{{ route('materials.show', $material) }}"
                    wire:key="card-{{ $material->id }}"
                    data-test="material-card"
                    x-data="swatchCard(@js($card))"
                    x-on:mouseenter="enter"
                    x-on:mousemove="move"
                    x-on:mouseleave="leave"
                    x-on:click="quickOpen($event, @js($material->code))"
                    x-bind:class="hovering && 'is-hovering'"
                    x-bind:style="tilt && { transform: tilt }"
                >
                    <x-ui.swatch-preview :card="$card" :targets="$targets" :tag="$material->category->code" :variants-count="$material->variants_count" :name="$material->name" />
                    <div class="ui-swatch-card__body">
                        <strong>{{ $material->name }}</strong>
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
                </a>
            @endforeach
        </div>
    @endif

    {{ $this->materials->links('vendor.pagination.opal') }}

    @if ($this->quickMaterial)
        <x-ui.material-modal
            :material="$this->quickMaterial"
            :card="$this->quickCard"
            :targets="$this->quickTargets"
            :variants-count="$this->quickMaterial->variants_count"
            :sessions="$this->revitSessions"
            :command="$this->revitCommand"
            :blocked="$this->applyBlockedReason()"
            :sets="$this->quickViewerSets"
        />
    @endif
</section>
