<?php

use App\Actions\Clients\IssueClientCommand;
use App\Actions\Materials\AddVariant;
use App\Actions\Representations\DeriveRepresentation;
use App\Actions\Representations\ReviewRepresentation;
use App\Actions\Versions\CutVersion;
use App\Actions\Versions\PublishVersion;
use App\Actions\Visibility\GrantMaterialAccess;
use App\Actions\Visibility\RevokeMaterialAccess;
use App\Actions\Visibility\SetMaterialVisibility;
use App\Enums\CommandType;
use App\Enums\ReviewState;
use App\Enums\Visibility;
use App\Jobs\EmbedMaterial;
use App\Library\Previews\MaterialPreviews;
use App\Models\ClientCommand;
use App\Models\ClientSession;
use App\Models\Drive;
use App\Models\FileAccess;
use App\Models\Material;
use App\Models\ProvenanceEvent;
use App\Models\QualityTier;
use App\Models\Representation;
use App\Models\Target;
use App\Models\Tenant;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public Material $material;

    public string $grantEmail = '';

    public string $grantDrive = '';

    public string $newColourway = '';

    public string $newColourwayCode = '';

    public int $userId = 0;

    public ?int $revitSessionId = null;

    public ?int $revitCommandId = null;

    public function mount(Material $material): void
    {
        $this->userId = (int) auth()->id();
        $this->revitSessionId = $this->revitSessions->first()?->id;

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
     * @return array{variants: list<array{id: int, code: string, name: string, hex: string, image: string|null}>, active: int}
     */
    #[Computed]
    public function card(): array
    {
        $previews = app(MaterialPreviews::class);
        $chips = $this->variants->take(24);

        return $previews->cardData($this->material, $chips, $previews->variantFilesFor($chips), $this->preview);
    }

    /**
     * Canonical maps per variant for the inspector, keyed by variant id.
     *
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function viewerSets(): array
    {
        return app(MaterialPreviews::class)->viewerSets($this->material, $this->variants);
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

    /**
     * Recent reads of this material's files, from the web and from drives.
     *
     * @return Collection<int, FileAccess>
     */
    #[Computed]
    public function accesses(): Collection
    {
        $fileIds = DB::table('representation_files')
            ->join('representations', 'representations.id', '=', 'representation_files.representation_id')
            ->whereIn('representations.variant_id', $this->material->variants()->select('id'))
            ->pluck('representation_files.file_id');

        return FileAccess::query()->with(['file', 'user', 'drive'])->whereIn('file_id', $fileIds)->orderByDesc('accessed_at')->orderByDesc('id')->limit(20)->get();
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

    /**
     * Why the record cannot be applied right now, or null when it can.
     */
    public function applyBlockedReason(): ?string
    {
        if ($this->revitSessions->isEmpty()) {
            return __('No Revit connected. Open OPAL → Connect in Revit.');
        }

        return $this->material->current_version_id === null
            ? __('Publish a version first; nothing is on the drive yet.')
            : null;
    }

    public function applyInRevit(int $variantId, IssueClientCommand $issue): void
    {
        $variant = $this->material->variants()->whereKey($variantId)->firstOrFail();
        $session = $this->revitSessions->firstWhere('id', $this->revitSessionId) ?? $this->revitSessions->first();

        if ($session === null || $this->applyBlockedReason() !== null) {
            Flux::toast(variant: 'warning', text: $this->applyBlockedReason() ?? __('No Revit session is connected.'));

            return;
        }

        $this->revitSessionId = $session->id;
        $command = $issue->handle($session, auth()->user(), CommandType::Apply, [
            'variant' => $variant->code,
            'material' => $this->material->code,
        ]);
        $this->revitCommandId = $command->id;
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
    }

    public function addVariant(AddVariant $addVariant): void
    {
        abort_unless(auth()->user()?->can('materials.contribute'), 403);

        $this->validate(['newColourway' => ['required', 'string', 'max:120'], 'newColourwayCode' => ['nullable', 'string', 'max:120']]);

        $addVariant->handle($this->material, ['colourway' => ['value' => $this->newColourway, 'supplier_code' => $this->newColourwayCode ?: null]]);
        if (config('opal.embeddings.enabled')) {
            EmbedMaterial::forMaterial($this->material, auth()->user());
        }
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

    public function generateTiers(int $variantId): void
    {
        abort_unless(auth()->user()?->can('materials.contribute'), 403);

        $variant = $this->material->variants()->findOrFail($variantId);
        $source = app(DeriveRepresentation::class)->canonicalFor($variant, QualityTier::fromSlug('8k'));
        $tiers = QualityTier::query()->whereNotNull('pixels')->where('pixels', '<', $source->quality->pixels ?? 0)->orderByDesc('pixels')->pluck('slug')->all();

        if ($tiers === []) {
            Flux::toast(variant: 'warning', text: __('No smaller tiers exist for this set.'));

            return;
        }

        $run = \App\Jobs\DownscaleRepresentation::forRepresentation($source, $tiers, auth()->user());

        unset($this->variants, $this->timeline);
        Flux::toast(variant: 'success', text: __('Queued tiers :tiers (run :run).', ['tiers' => implode(', ', $tiers), 'run' => substr($run->uuid, 0, 8)]));
    }

    public function renderPreview(int $variantId): void
    {
        abort_unless(auth()->user()?->can('materials.contribute'), 403);

        $variant = $this->material->variants()->findOrFail($variantId);

        if (\App\Jobs\RenderPreview::sourceFor($variant) === null) {
            Flux::toast(variant: 'warning', text: __('Approve a canonical set with a base colour first.'));

            return;
        }

        $run = \App\Jobs\RenderPreview::forVariant($variant, auth()->user(), force: true);

        unset($this->variants, $this->timeline, $this->preview, $this->card);
        Flux::toast(variant: 'success', text: __('Queued a preview render (run :run).', ['run' => substr($run->uuid, 0, 8)]));
    }

    public function refreshEmbedding(): void
    {
        abort_unless(auth()->user()?->can('materials.contribute'), 403);

        $run = EmbedMaterial::forMaterial($this->material, auth()->user(), force: true);
        Flux::toast(variant: 'success', text: __('Queued a fresh similarity index (run :run).', ['run' => substr($run->uuid, 0, 8)]));
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

<section x-data="swatchCard(@js($this->card))">
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
            @if (config('opal.embeddings.enabled'))
                <x-ui.button href="{{ route('materials.index', ['similar' => $material->code]) }}" variant="quiet" size="sm" data-test="find-similar">{{ __('Find similar') }}</x-ui.button>
            @endif
            @can('materials.contribute')
                @if (config('opal.embeddings.enabled'))
                    <x-ui.button wire:click="refreshEmbedding" variant="quiet" size="sm" data-test="refresh-embedding">{{ __('Refresh similarity') }}</x-ui.button>
                @endif
            @endcan
            @can('materials.publish')
                <x-ui.button wire:click="publish" variant="secondary" size="sm" data-test="publish">{{ __('Publish new version') }}</x-ui.button>
            @endcan
            <div class="ui-revit" data-test="apply-in-revit">
                <button
                    type="button"
                    class="ui-button ui-button--primary ui-button--sm ui-revit__apply"
                    x-on:click="$wire.applyInRevit(chosen.id)"
                    @disabled($this->applyBlockedReason() !== null)
                    data-test="apply-in-revit-button"
                >
                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 10h9M10 6l4 4-4 4" /><path d="M16 4v12" /></svg>
                    <span>{{ __('Apply in Revit') }}</span>
                    <em x-text="chosen?.name ?? ''">{{ $this->card['variants'][$this->card['active']]['name'] ?? '' }}</em>
                </button>
                @if ($this->applyBlockedReason())
                    <span class="ui-revit__none">{{ $this->applyBlockedReason() }}</span>
                @elseif ($this->revitSessions->count() > 1)
                    <select class="ui-select ui-select--sm" wire:model.live="revitSessionId" aria-label="{{ __('Revit session') }}">
                        @foreach ($this->revitSessions as $session)
                            <option value="{{ $session->id }}">{{ $session->label() }}</option>
                        @endforeach
                    </select>
                @else
                    <span class="ui-revit__target">{{ $this->revitSessions->first()->label() }}</span>
                @endif
            </div>
        </div>
    </div>

    <div class="ui-bento" style="margin-bottom: 12px">
        <x-ui.panel class="ui-bento__wide ui-swatch-card--hero" :padding="false" data-test="material-hero">
            <x-ui.swatch-preview :card="$this->card" :targets="$this->targets" :tag="$material->category->code" :variants-count="$this->variants->count()" :name="$material->name" :open="true" style="aspect-ratio: 21 / 9; border-bottom: 0; border-radius: inherit" />
        </x-ui.panel>
        <div class="ui-stat-stack">
            <x-ui.stat :label="__('Variants')" :value="$this->variants->count()" />
            <x-ui.stat :label="__('Representations')" :value="$this->variants->sum(fn ($v) => $v->representations->count())" :detail="__('across all variants')" />
            <x-ui.stat :label="__('Provenance events')" :value="$this->timeline->count()" />
        </div>
    </div>

    <div
        class="ui-viewer"
        style="margin-bottom: 12px"
        x-data="materialViewer(@js(['sets' => $this->viewerSets, 'objectSizeMm' => 1000]))"
        x-effect="show(chosen?.id)"
        data-test="material-viewer"
    >
        <div class="ui-viewer__bar">
            <p>
                {{ __('Inspector') }} ·
                <span x-text="view === 'surface' ? '{{ __('canonical maps under studio light') }}' : `${activeMap?.label ?? ''} · {{ __('raw map') }}`">{{ __('canonical maps under studio light') }}</span>
                · <span x-text="view === 'surface' ? status : '{{ __('ready') }}'"></span>
            </p>
            <div class="ui-segment" role="group" aria-label="{{ __('Shape') }}" x-show="view === 'surface'">
                <button type="button" x-on:click="shape = 'ball'" x-bind:class="shape === 'ball' && 'is-active'">{{ __('Ball') }}</button>
                <button type="button" x-on:click="shape = 'panel'" x-bind:class="shape === 'panel' && 'is-active'">{{ __('Panel') }}</button>
                <button type="button" x-on:click="shape = 'cube'" x-bind:class="shape === 'cube' && 'is-active'">{{ __('Cube') }}</button>
            </div>
        </div>
        @if ($this->viewerSets === [])
            <div class="ui-viewer__empty">{{ __('No canonical maps to inspect yet') }}</div>
        @else
            <div class="ui-viewer__stage" x-ref="stage" wire:ignore>
                <x-ui.material-map-inspector />
            </div>
        @endif
    </div>

    @if ($material->description)
        <x-ui.panel style="margin-bottom: 12px"><p style="margin: 0; color: var(--ui-muted); font-size: 13px; line-height: 1.7">{{ $material->description }}</p></x-ui.panel>
    @endif

    @if ($this->revitCommand)
        <x-ui.panel style="margin-bottom: 12px" data-test="revit-command">
            <p class="ui-revit__status" data-status="{{ $this->revitCommand->status->value }}">
                <span class="ui-status-light ui-status-light--{{ $this->revitCommand->status->value }}" aria-hidden="true"></span>
                <strong>{{ __('Apply :variant', ['variant' => $this->revitCommand->payload['variant'] ?? '']) }}</strong>
                <span>→ {{ $this->revitCommand->session->label() }}</span>
                <span>· {{ $this->revitCommand->status->label() }}</span>
                @if ($this->revitCommand->message)<span>· {{ $this->revitCommand->message }}</span>@endif
            </p>
        </x-ui.panel>
    @endif

    <div class="ui-stack">
        @foreach ($this->variants as $variant)
            <div class="ui-variant" wire:key="variant-{{ $variant->id }}" data-test="variant" id="variant-{{ $variant->id }}" x-bind:class="current && current.id === {{ $variant->id }} && 'is-highlighted'" x-on:mouseenter="preview({{ $loop->index }})" x-on:mouseleave="clearPreview()" x-on:click="pick({{ $loop->index }})">
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
                                <x-ui.button wire:click="generateTiers({{ $variant->id }})" variant="quiet" size="sm" data-test="generate-tiers">{{ __('Generate tiers') }}</x-ui.button>
                                <x-ui.button wire:click="renderPreview({{ $variant->id }})" variant="quiet" size="sm" data-test="render-preview">{{ __('Render preview') }}</x-ui.button>
                            </div>
                        @endcan
                    </div>

                    @if ($variant->representations->isEmpty())
                        <p class="ui-variant__attrs" style="margin-top: 10px">{{ __('No representations yet.') }}</p>
                    @else
                        <div class="ui-table-scroll">
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
                        </div>
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

    <x-ui.panel style="margin-top: 12px" data-test="access-trail">
        <div class="ui-panel__heading"><div><h3>{{ __('Access') }}</h3><p>{{ __('Recent reads of this material\'s files, from the web and from mounted drives.') }}</p></div></div>
        @if ($this->accesses->isEmpty())
            <p class="ui-variant__attrs">{{ __('No reads recorded.') }}</p>
        @else
            <div class="ui-table-scroll">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th>{{ __('When') }}</th>
                            <th>{{ __('Channel') }}</th>
                            <th>{{ __('Who') }}</th>
                            <th>{{ __('Operation') }}</th>
                            <th>{{ __('File') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->accesses as $access)
                            <tr wire:key="access-{{ $access->id }}" data-test="access-row">
                                <td><time datetime="{{ $access->accessed_at->toIso8601String() }}">{{ $access->accessed_at->format('Y-m-d H:i') }}</time></td>
                                <td><span class="ui-code">{{ $access->drive?->name ?? $access->channel }}</span></td>
                                <td>{{ $access->who() }}</td>
                                <td>{{ $access->action }}@if ($access->result && $access->result !== 'allow') · {{ $access->result }}@endif</td>
                                <td>{{ $access->file?->original_name ?? basename((string) $access->path) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-ui.panel>
</section>
