<?php

use App\Jobs\RenderPreview;
use App\Library\Quality\LibraryQuality;
use App\Models\Material;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Quality')] class extends Component {
    use WithPagination;

    /** @var list<string> */
    #[Url]
    public array $gaps = [];

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $sort = 'worst';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('materials.view'), 403);
    }

    public function toggleGap(string $gap): void
    {
        $this->gaps = in_array($gap, $this->gaps, true)
            ? array_values(array_diff($this->gaps, [$gap]))
            : [...$this->gaps, $gap];

        $this->resetPage();
    }

    public function clearGaps(): void
    {
        $this->gaps = [];
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function setSort(string $sort): void
    {
        $this->sort = in_array($sort, ['worst', 'name'], true) ? $sort : 'worst';
    }

    /**
     * @return array{total: int, with_files: int, gaps: array<string, int>}
     */
    #[Computed]
    public function summary(): array
    {
        return app(LibraryQuality::class)->summary(auth()->user());
    }

    #[Computed]
    public function materials(): LengthAwarePaginator
    {
        return app(LibraryQuality::class)->materials(auth()->user(), $this->gaps, $this->search, $this->sort);
    }

    /** Queue a preview render for every variant of a material that lacks one. */
    public function renderPreviews(int $materialId): void
    {
        abort_unless(auth()->user()?->can('materials.contribute'), 403);

        $material = Material::query()->visibleTo(auth()->user())->findOrFail($materialId);
        $queued = 0;

        foreach ($material->variants as $variant) {
            if (RenderPreview::sourceFor($variant) !== null) {
                RenderPreview::forVariant($variant, auth()->user(), force: true);
                $queued++;
            }
        }

        unset($this->materials, $this->summary);

        $queued === 0
            ? Flux::toast(variant: 'warning', text: __('Nothing to render: approve a canonical set with a base colour first.'))
            : Flux::toast(variant: 'success', text: trans_choice('Queued :count preview render|Queued :count preview renders', $queued, ['count' => $queued]));
    }

    /**
     * @return array<string, string>
     */
    public function labels(): array
    {
        return [
            'no-canonical' => __('No canonical set'),
            'low-resolution' => __('Under 1k'),
            'no-normal' => __('No normal'),
            'no-roughness' => __('No roughness'),
            'no-ao' => __('No AO'),
            'no-revit' => __('No Revit set'),
            'no-preview' => __('No render'),
            'unpublished' => __('Unpublished'),
        ];
    }
}; ?>

<section>
    <div class="ui-page-head">
        <div>
            <x-ui.eyebrow>{{ __('Library') }}</x-ui.eyebrow>
            <h1>{{ __('Quality') }} <small>{{ number_format($this->summary['with_files']) }} / {{ number_format($this->summary['total']) }} {{ __('with files') }}</small></h1>
            <p class="ui-page-head__lede">{{ __('What each material is missing: canonical maps at a workable size, the maps that carry surface detail, a set Revit can read, and a rendered preview. Pick a gap to see the queue behind it.') }}</p>
        </div>
    </div>

    <div class="ui-gaps" data-test="gap-tiles">
        @foreach ($this->labels() as $gap => $label)
            <button
                type="button"
                class="ui-gap"
                wire:click="toggleGap('{{ $gap }}')"
                @class(['is-active' => in_array($gap, $gaps, true)])
                data-test="gap-{{ $gap }}"
            >
                <strong>{{ number_format($this->summary['gaps'][$gap] ?? 0) }}</strong>
                <span>{{ $label }}</span>
            </button>
        @endforeach
    </div>

    <div class="ui-toolbar">
        <label class="ui-search">
            <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="9" cy="9" r="5.5" /><path d="m13.5 13.5 3 3" /></svg>
            <input class="ui-input" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search materials…') }}" aria-label="{{ __('Search materials') }}" data-test="quality-search" />
        </label>
        <div class="ui-segment" role="group" aria-label="{{ __('Order') }}">
            <button type="button" wire:click="setSort('worst')" @class(['is-active' => $sort === 'worst'])>{{ __('Worst first') }}</button>
            <button type="button" wire:click="setSort('name')" @class(['is-active' => $sort === 'name'])>{{ __('By name') }}</button>
        </div>
        @if ($gaps !== [])
            <x-ui.button wire:click="clearGaps" variant="quiet" size="sm" data-test="clear-gaps">{{ trans_choice('Clear :count filter|Clear :count filters', count($gaps), ['count' => count($gaps)]) }}</x-ui.button>
        @endif
    </div>

    @if ($this->materials->isEmpty())
        <x-ui.panel data-test="quality-empty">
            <x-ui.empty-state :title="__('Nothing matches')" :description="__('No material has all of those gaps at once.')" />
        </x-ui.panel>
    @else
        <div class="ui-data-shell">
            <div class="ui-table-scroll">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th>{{ __('Material') }}</th>
                            <th>{{ __('Category') }}</th>
                            <th>{{ __('Variants') }}</th>
                            <th>{{ __('Canonical') }}</th>
                            <th>{{ __('Targets') }}</th>
                            <th>{{ __('Gaps') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->materials as $material)
                            @php
                                $gapList = $material->getAttribute('gaps') ?? [];
                                $targets = array_filter(explode(',', (string) $material->getAttribute('targets')));
                                $roles = array_filter(explode(',', (string) $material->getAttribute('canonical_roles')));
                                $pixels = $material->getAttribute('canonical_pixels');
                            @endphp
                            <tr wire:key="quality-{{ $material->id }}" data-test="quality-row">
                                <td>
                                    <a class="ui-table__material" href="{{ route('materials.show', $material) }}" wire:navigate style="text-decoration: none">
                                        <span class="ui-table__swatch" style="background: {{ \App\Library\Previews\MaterialPreviews::fallbackHex($material->code) }}"></span>
                                        <div>
                                            <strong>{{ $material->name }}</strong>
                                            <small>{{ $material->code }}</small>
                                        </div>
                                    </a>
                                </td>
                                <td>{{ $material->category->name }}</td>
                                <td>{{ $material->variants_count }}</td>
                                <td>
                                    @if ($pixels)
                                        <span class="ui-code">{{ $pixels >= 1024 ? round($pixels / 1024, 1).'k' : $pixels.'px' }}</span>
                                        <small style="display: block; color: var(--ui-muted)">{{ implode(', ', $roles) ?: '—' }}</small>
                                    @else
                                        <span style="color: var(--ui-muted)">—</span>
                                    @endif
                                </td>
                                <td>
                                    @forelse ($targets as $target)
                                        <span class="ui-tag" data-badge="{{ $target }}" data-state="approved">{{ \App\Library\Previews\MaterialPreviews::badgeLabels()[$target] ?? strtoupper($target) }}</span>
                                    @empty
                                        <span style="color: var(--ui-muted)">—</span>
                                    @endforelse
                                </td>
                                <td>
                                    @forelse ($gapList as $gap)
                                        <span class="ui-tag ui-tag--faint">{{ $this->labels()[$gap] ?? $gap }}</span>
                                    @empty
                                        <x-ui.badge tone="success" dot>{{ __('Complete') }}</x-ui.badge>
                                    @endforelse
                                </td>
                                <td>
                                    @can('materials.contribute')
                                        @if (in_array('no-preview', $gapList, true) && ! in_array('no-canonical', $gapList, true))
                                            <x-ui.button wire:click="renderPreviews({{ $material->id }})" variant="quiet" size="sm" data-test="render-previews-{{ $material->id }}">{{ __('Render') }}</x-ui.button>
                                        @endif
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{ $this->materials->links('vendor.pagination.opal') }}
</section>
