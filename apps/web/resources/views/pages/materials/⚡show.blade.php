<?php

use App\Actions\Materials\AddVariant;
use App\Actions\Representations\DeriveRepresentation;
use App\Actions\Representations\ReviewRepresentation;
use App\Actions\Versions\CutVersion;
use App\Actions\Versions\PublishVersion;
use App\Actions\Visibility\GrantMaterialAccess;
use App\Actions\Visibility\RevokeMaterialAccess;
use App\Actions\Visibility\SetMaterialVisibility;
use App\Enums\ReviewState;
use App\Enums\Visibility;
use App\Library\Previews\MaterialPreviews;
use App\Models\Drive;
use App\Models\Material;
use App\Models\ProvenanceEvent;
use App\Models\QualityTier;
use App\Models\Representation;
use App\Models\Target;
use App\Models\Tenant;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Material $material;

    public string $grantEmail = '';

    public string $grantDrive = '';

    public string $newColourway = '';

    public string $newColourwayCode = '';

    public function mount(Material $material): void
    {
        abort_unless(auth()->user()?->can('materials.view') && $material->isVisibleTo(auth()->user()), 403);

        $this->material = $material;
    }

    public function rendering($view): void
    {
        $view->title($this->material->name);
    }

    #[Computed]
    public function variants(): Collection
    {
        return $this->material->variants()->with(['attributes.type', 'representations.target', 'representations.quality', 'representations.representationFiles.role', 'representations.representationFiles.file'])->get();
    }

    #[Computed]
    public function preview(): ?\App\Models\File
    {
        return app(MaterialPreviews::class)->filesFor($this->material->newCollection([$this->material]))[$this->material->id] ?? null;
    }

    /**
     * @return array{variants: list<array{id: int, name: string, hex: string, image: string|null}>, active: int}
     */
    #[Computed]
    public function card(): array
    {
        $previews = app(MaterialPreviews::class);
        $chips = $this->variants->take(24);

        return $previews->cardData($this->material, $chips, $previews->variantFilesFor($chips), $this->preview);
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function targets(): array
    {
        return app(MaterialPreviews::class)->targetsFor($this->material->newCollection([$this->material]))[$this->material->id] ?? [];
    }

    #[Computed]
    public function versions(): Collection
    {
        return $this->material->versions()->withCount('representations')->orderByDesc('number')->get();
    }

    #[Computed]
    public function grants(): Collection
    {
        return $this->material->grants()->with('grantee')->get();
    }

    #[Computed]
    public function timeline(): Collection
    {
        $variantIds = $this->material->variants()->select('id');
        $representationIds = Representation::query()->whereIn('variant_id', $variantIds)->select('id');

        return ProvenanceEvent::query()
            ->where(function ($query) use ($variantIds, $representationIds): void {
                $query->where(fn ($q) => $q->where('subject_type', 'material')->where('subject_id', $this->material->getKey()))
                    ->orWhere(fn ($q) => $q->where('subject_type', 'variant')->whereIn('subject_id', $variantIds))
                    ->orWhere(fn ($q) => $q->where('subject_type', 'representation')->whereIn('subject_id', $representationIds));
            })
            ->with(['actor', 'source', 'outputs'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    #[Computed]
    public function drives(): Collection
    {
        return Drive::query()->orderBy('name')->get();
    }

    #[Computed]
    public function derivableTargets(): Collection
    {
        return Target::query()->where('is_canonical', false)->where('slug', '!=', 'preview')->orderBy('sort_order')->get();
    }

    public function addVariant(AddVariant $addVariant): void
    {
        abort_unless(auth()->user()?->can('materials.contribute'), 403);

        $this->validate(['newColourway' => ['required', 'string', 'max:120'], 'newColourwayCode' => ['nullable', 'string', 'max:120']]);

        $addVariant->handle($this->material, ['colourway' => ['value' => $this->newColourway, 'supplier_code' => $this->newColourwayCode ?: null]]);
        $this->reset('newColourway', 'newColourwayCode');
        unset($this->variants);
        Flux::toast(variant: 'success', text: __('Variant added.'));
    }

    public function review(int $representationId, string $decision, ReviewRepresentation $review): void
    {
        abort_unless(auth()->user()?->can('materials.review'), 403);

        $representation = Representation::query()->whereIn('variant_id', $this->material->variants()->select('id'))->findOrFail($representationId);
        $review->handle($representation, ReviewState::from($decision), auth()->user());

        unset($this->variants, $this->timeline, $this->preview);
        Flux::toast(variant: 'success', text: __('Representation :decision.', ['decision' => $decision]));
    }

    public function derive(int $variantId, string $target, DeriveRepresentation $derive): void
    {
        abort_unless(auth()->user()?->can('materials.contribute'), 403);

        $variant = $this->material->variants()->findOrFail($variantId);
        $canonical = $derive->canonicalFor($variant, QualityTier::fromSlug('8k'));
        $derive->handle($variant, $target, $canonical->quality, auth()->user());

        unset($this->variants, $this->timeline);
        Flux::toast(variant: 'success', text: __(':target set derived as a candidate.', ['target' => $target]));
    }

    public function publish(CutVersion $cut, PublishVersion $publish): void
    {
        abort_unless(auth()->user()?->can('materials.publish'), 403);

        $version = $publish->handle($cut->handle($this->material, auth()->user()), auth()->user());
        $this->material->refresh();

        unset($this->versions);
        Flux::toast(variant: 'success', text: __('Version :number published.', ['number' => $version->number]));
    }

    public function makeCurrent(int $versionId, PublishVersion $publish): void
    {
        abort_unless(auth()->user()?->can('materials.publish'), 403);

        $publish->handle($this->material->versions()->findOrFail($versionId), auth()->user());
        $this->material->refresh();

        unset($this->versions);
        Flux::toast(variant: 'success', text: __('Current version changed.'));
    }

    public function setVisibility(string $visibility, SetMaterialVisibility $set): void
    {
        abort_unless(auth()->user()?->can('materials.publish'), 403);

        $set->handle($this->material, Visibility::from($visibility));
        $this->material->refresh();
    }

    public function grantUser(GrantMaterialAccess $grant): void
    {
        abort_unless(auth()->user()?->can('materials.publish'), 403);

        $this->validate(['grantEmail' => ['required', 'email', 'exists:users,email']]);
        $grant->handle($this->material, User::query()->where('email', $this->grantEmail)->sole(), auth()->user());
        $this->reset('grantEmail');
        unset($this->grants);
    }

    public function grantDrive(GrantMaterialAccess $grant): void
    {
        abort_unless(auth()->user()?->can('materials.publish'), 403);

        $this->validate(['grantDrive' => ['required', 'exists:drives,id']]);
        $grant->handle($this->material, Drive::query()->findOrFail($this->grantDrive), auth()->user());
        $this->reset('grantDrive');
        unset($this->grants);
    }

    public function revoke(int $grantId, RevokeMaterialAccess $revoke): void
    {
        abort_unless(auth()->user()?->can('materials.publish'), 403);

        $grantee = $this->material->grants()->findOrFail($grantId)->grantee;

        if ($grantee instanceof User || $grantee instanceof Drive || $grantee instanceof Tenant) {
            $revoke->handle($this->material, $grantee);
        }

        unset($this->grants);
    }
}; ?>

<section x-data="swatchCard(@js([...$this->card, 'sticky' => true]))">
    <div class="ui-record-head">
        <div>
            <x-ui.eyebrow>{{ $material->category->name }}</x-ui.eyebrow>
            <h1>{{ $material->name }}</h1>
            <div class="ui-record-head__facts">
                <code data-test="material-code">{{ $material->code }}</code>
                <span>{{ $material->supplier?->name ?? __('In-house') }}</span>
                @if ($material->supplier_product_code)<span>{{ __('Product :code', ['code' => $material->supplier_product_code]) }}</span>@endif
                @if ($material->collection)<span>{{ $material->collection }}</span>@endif
                @if ($material->tile_width_mm)<span>{{ (float) $material->tile_width_mm }} × {{ (float) $material->tile_height_mm }} mm</span>@endif
            </div>
        </div>
        <div class="ui-page-head__actions">
            <x-ui.badge :tone="match ($material->status->value) { 'active' => 'success', 'archived' => 'neutral', default => 'warning' }" dot>{{ $material->status->label() }}</x-ui.badge>
            <x-ui.badge tone="info" data-test="current-version">{{ $material->currentVersion ? 'v'.$material->currentVersion->number : __('Unpublished') }}</x-ui.badge>
            @can('materials.publish')
                <x-ui.button wire:click="publish" size="sm" data-test="publish">{{ __('Publish new version') }}</x-ui.button>
            @endcan
        </div>
    </div>

    <div class="ui-bento" style="margin-bottom: 12px">
        <x-ui.panel class="ui-bento__wide ui-swatch-card ui-swatch-card--hero" :padding="false" x-on:mouseenter="enter" x-on:mousemove="move" x-on:mouseleave="leave" x-bind:class="hovering && 'is-hovering'" x-bind:style="tilt && { transform: tilt }" data-test="material-hero">
            <x-ui.swatch-preview :card="$this->card" :targets="$this->targets" :tag="$material->category->code" :variants-count="$this->variants->count()" :name="$material->name" :open="true" style="aspect-ratio: 21 / 9; border-bottom: 0; border-radius: inherit" />
        </x-ui.panel>
        <div class="ui-stat-stack">
            <x-ui.stat :label="__('Variants')" :value="$this->variants->count()" />
            <x-ui.stat :label="__('Representations')" :value="$this->variants->sum(fn ($v) => $v->representations->count())" :detail="__('across all variants')" />
            <x-ui.stat :label="__('Provenance events')" :value="$this->timeline->count()" />
        </div>
    </div>

    @if ($material->description)
        <x-ui.panel style="margin-bottom: 12px"><p style="margin: 0; color: var(--ui-muted); font-size: 13px; line-height: 1.7">{{ $material->description }}</p></x-ui.panel>
    @endif

    <div class="ui-stack">
        @foreach ($this->variants as $variant)
            <div class="ui-variant" wire:key="variant-{{ $variant->id }}" data-test="variant" id="variant-{{ $variant->id }}" x-bind:class="current && current.id === {{ $variant->id }} && 'is-highlighted'" x-on:mouseenter="pick({{ $loop->index }})">
                <div class="ui-variant__chip" style="--chip: {{ $variant->dominant_hex ?? \App\Library\Previews\MaterialPreviews::fallbackHex($variant->code) }}" aria-hidden="true"></div>
                <div>
                    <div class="ui-variant__head">
                        <div>
                            <strong>{{ $variant->name }}</strong>
                            <span class="ui-code" style="margin-left: 8px">{{ $variant->code }}</span>
                            <p class="ui-variant__attrs">
                                @forelse ($variant->attributes as $attribute)
                                    <span>{{ $attribute->type->name }}: {{ $attribute->value }}@if ($attribute->supplier_code) ({{ $attribute->supplier_code }})@endif</span>
                                @empty
                                    <span>{{ __('No attributes') }}</span>
                                @endforelse
                            </p>
                        </div>
                        @can('materials.contribute')
                            <div class="ui-actions">
                                @foreach ($this->derivableTargets as $target)
                                    <x-ui.button wire:click="derive({{ $variant->id }}, '{{ $target->slug }}')" variant="secondary" size="sm" data-test="derive-{{ $target->slug }}">{{ __('Derive :target', ['target' => $target->name]) }}</x-ui.button>
                                @endforeach
                            </div>
                        @endcan
                    </div>

                    @if ($variant->representations->isEmpty())
                        <p class="ui-variant__attrs" style="margin-top: 10px">{{ __('No representations yet.') }}</p>
                    @else
                        <table class="ui-table">
                            <thead>
                                <tr>
                                    <th>{{ __('Target') }}</th>
                                    <th>{{ __('Quality') }}</th>
                                    <th>{{ __('Kind') }}</th>
                                    <th>{{ __('Files') }}</th>
                                    <th>{{ __('State') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($variant->representations->sortBy([['target.sort_order', 'asc'], ['id', 'desc']]) as $representation)
                                    <tr wire:key="rep-{{ $representation->id }}" data-test="representation">
                                        <td><strong style="color: var(--ui-ink)">{{ $representation->target->name }}</strong></td>
                                        <td>{{ $representation->quality->name }}</td>
                                        <td><span class="ui-code">{{ $representation->kind }}</span></td>
                                        <td>
                                            @foreach ($representation->representationFiles as $representationFile)
                                                <a class="ui-code" href="{{ $representationFile->file->url() }}" target="_blank" rel="noopener">{{ $representationFile->role->slug }}</a>@if (! $loop->last), @endif
                                            @endforeach
                                        </td>
                                        <td>
                                            <x-ui.badge :tone="match ($representation->review_state->value) { 'approved' => 'success', 'rejected' => 'danger', 'superseded' => 'neutral', default => 'warning' }" dot data-test="review-state">{{ $representation->review_state->label() }}</x-ui.badge>
                                        </td>
                                        <td>
                                            @can('materials.review')
                                                @if ($representation->review_state->value === 'candidate')
                                                    <span class="ui-actions">
                                                        <x-ui.button wire:click="review({{ $representation->id }}, 'approved')" size="sm" data-test="approve">{{ __('Approve') }}</x-ui.button>
                                                        <x-ui.button wire:click="review({{ $representation->id }}, 'rejected')" variant="danger" size="sm" data-test="reject">{{ __('Reject') }}</x-ui.button>
                                                    </span>
                                                @endif
                                            @endcan
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>
        @endforeach

        @can('materials.contribute')
            <x-ui.panel tone="paper">
                <form wire:submit="addVariant" class="ui-inline-form">
                    <x-ui.field :label="__('New colourway')" for="newColourway" :error="$errors->first('newColourway')">
                        <input id="newColourway" class="ui-input" wire:model="newColourway" placeholder="Slate" data-test="new-colourway" />
                    </x-ui.field>
                    <x-ui.field :label="__('Supplier colour code')" for="newColourwayCode">
                        <input id="newColourwayCode" class="ui-input" wire:model="newColourwayCode" />
                    </x-ui.field>
                    <x-ui.button type="submit" variant="secondary" data-test="add-variant">{{ __('Add variant') }}</x-ui.button>
                </form>
            </x-ui.panel>
        @endcan
    </div>

    <div class="ui-grid-2" style="margin-top: 12px">
        <x-ui.panel>
            <div class="ui-panel__heading"><div><h3>{{ __('Versions') }}</h3><p>{{ __('Immutable snapshots of approved representations.') }}</p></div></div>
            @if ($this->versions->isEmpty())
                <p class="ui-variant__attrs">{{ __('Nothing published yet. Approve at least one representation, then publish.') }}</p>
            @else
                <ul class="ui-list">
                    @foreach ($this->versions as $version)
                        <li wire:key="version-{{ $version->id }}" data-test="version">
                            <span><strong>v{{ $version->number }}</strong> <small>· {{ $version->status->value }} · {{ trans_choice(':count representation|:count representations', $version->representations_count) }}</small></span>
                            @if ($version->isCurrent())
                                <x-ui.badge tone="success" dot>{{ __('Current') }}</x-ui.badge>
                            @elsecan('materials.publish')
                                <x-ui.button wire:click="makeCurrent({{ $version->id }})" variant="quiet" size="sm" data-test="make-current">{{ __('Make current') }}</x-ui.button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.panel>

        <x-ui.panel data-test="visibility">
            <div class="ui-panel__heading"><div><h3>{{ __('Visibility') }}</h3><p>{{ $material->visibility->label() }}</p></div></div>
            @can('materials.publish')
                <div class="ui-actions">
                    <x-ui.button wire:click="setVisibility('library')" :variant="$material->visibility->value === 'library' ? 'primary' : 'quiet'" size="sm" data-test="visibility-library">{{ __('Whole library') }}</x-ui.button>
                    <x-ui.button wire:click="setVisibility('restricted')" :variant="$material->visibility->value === 'restricted' ? 'primary' : 'quiet'" size="sm" data-test="visibility-restricted">{{ __('Restricted') }}</x-ui.button>
                </div>
                <ul class="ui-list" style="margin-top: 12px">
                    @forelse ($this->grants as $grant)
                        <li wire:key="grant-{{ $grant->id }}" data-test="grant">
                            <span><small>{{ strtoupper($grant->grantee_type) }}</small> <strong>{{ $grant->grantee?->name ?? $grant->grantee_id }}</strong></span>
                            <x-ui.button wire:click="revoke({{ $grant->id }})" variant="ghost" size="sm">{{ __('Revoke') }}</x-ui.button>
                        </li>
                    @empty
                        <li><small>{{ __('No grants.') }}</small></li>
                    @endforelse
                </ul>
                <form wire:submit="grantUser" class="ui-inline-form" style="margin-top: 12px">
                    <x-ui.field :label="__('Grant a user')" for="grantEmail" :error="$errors->first('grantEmail')">
                        <input id="grantEmail" class="ui-input" type="email" wire:model="grantEmail" placeholder="colleague@example.com" data-test="grant-email" />
                    </x-ui.field>
                    <x-ui.button type="submit" variant="secondary" size="sm" data-test="grant-user">{{ __('Grant') }}</x-ui.button>
                </form>
                <form wire:submit="grantDrive" class="ui-inline-form" style="margin-top: 12px">
                    <x-ui.field :label="__('Grant a drive')" for="grantDrive" :error="$errors->first('grantDrive')">
                        <select id="grantDrive" class="ui-select" wire:model="grantDrive" data-test="grant-drive-select">
                            <option value="">{{ __('Choose a drive') }}</option>
                            @foreach ($this->drives as $drive)
                                <option value="{{ $drive->id }}">{{ $drive->name }}</option>
                            @endforeach
                        </select>
                    </x-ui.field>
                    <x-ui.button type="submit" variant="secondary" size="sm" data-test="grant-drive">{{ __('Grant') }}</x-ui.button>
                </form>
            @endcan
        </x-ui.panel>
    </div>

    <x-ui.panel style="margin-top: 12px">
        <div class="ui-panel__heading"><div><h3>{{ __('Provenance') }}</h3><p>{{ __('Who did what, with which tool, from which source.') }}</p></div></div>
        @if ($this->timeline->isEmpty())
            <p class="ui-variant__attrs">{{ __('No events recorded.') }}</p>
        @else
            <div class="ui-activity">
                @foreach ($this->timeline as $event)
                    <div class="ui-activity__row" wire:key="event-{{ $event->id }}" data-test="provenance-event">
                        <span class="ui-activity__mark">{{ strtoupper(substr($event->action, 0, 2)) }}</span>
                        <div>
                            <strong>{{ $event->describe() }}</strong>
                            <small>
                                @if ($event->source){{ $event->source->name }} · @endif
                                @if ($event->outputs->isNotEmpty()){{ $event->outputs->pluck('original_name')->filter()->implode(', ') }} · @endif
                                @if ($event->notes){{ $event->notes }}@endif
                            </small>
                        </div>
                        <time datetime="{{ $event->occurred_at->toIso8601String() }}">{{ $event->occurred_at->format('Y-m-d H:i') }}</time>
                    </div>
                @endforeach
            </div>
        @endif
    </x-ui.panel>
</section>
