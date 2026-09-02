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
use App\Models\Drive;
use App\Models\Material;
use App\Models\MaterialVersion;
use App\Models\ProvenanceEvent;
use App\Models\Representation;
use App\Models\Target;
use App\Models\User;
use App\Models\Variant;
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
        return $this->material->variants()->with(['attributes.type', 'representations.target', 'representations.quality', 'representations.representationFiles'])->get();
    }

    #[Computed]
    public function versions(): Collection
    {
        return $this->material->versions()->withCount('representations')->orderByDesc('number')->get();
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

        unset($this->variants, $this->timeline);
        Flux::toast(variant: 'success', text: __('Representation :decision.', ['decision' => $decision]));
    }

    public function derive(int $variantId, string $target, DeriveRepresentation $derive): void
    {
        abort_unless(auth()->user()?->can('materials.contribute'), 403);

        $variant = $this->material->variants()->findOrFail($variantId);
        $canonical = $derive->canonicalFor($variant, \App\Models\QualityTier::fromSlug('8k'));
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
        $this->material->unsetRelation('grants');
    }

    public function grantDrive(GrantMaterialAccess $grant): void
    {
        abort_unless(auth()->user()?->can('materials.publish'), 403);

        $this->validate(['grantDrive' => ['required', 'exists:drives,id']]);
        $grant->handle($this->material, Drive::query()->findOrFail($this->grantDrive), auth()->user());
        $this->reset('grantDrive');
        $this->material->unsetRelation('grants');
    }

    public function revoke(int $grantId, RevokeMaterialAccess $revoke): void
    {
        abort_unless(auth()->user()?->can('materials.publish'), 403);

        $grant = $this->material->grants()->findOrFail($grantId);
        $grantee = $grant->grantee;

        if ($grantee instanceof User || $grantee instanceof Drive || $grantee instanceof \App\Models\Tenant) {
            $revoke->handle($this->material, $grantee);
        }

        $this->material->unsetRelation('grants');
    }
}; ?>

<section class="w-full space-y-10">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <code class="text-xs text-zinc-500" data-test="material-code">{{ $material->code }}</code>
            <flux:heading size="xl">{{ $material->name }}</flux:heading>
            <flux:text class="mt-1">
                {{ $material->category->name }}
                · {{ $material->supplier?->name ?? __('In-house') }}
                @if ($material->supplier_product_code) · {{ $material->supplier_product_code }} @endif
            </flux:text>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <flux:badge :color="match ($material->status->value) { 'active' => 'lime', 'archived' => 'zinc', default => 'amber' }">{{ $material->status->label() }}</flux:badge>
            <flux:badge color="zinc" data-test="current-version">{{ $material->currentVersion ? 'v'.$material->currentVersion->number : __('Unpublished') }}</flux:badge>
            @can('materials.publish')
                <flux:button wire:click="publish" variant="primary" size="sm" data-test="publish">{{ __('Publish new version') }}</flux:button>
            @endcan
        </div>
    </div>

    @foreach ($this->variants as $variant)
        <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700" data-test="variant" wire:key="variant-{{ $variant->id }}">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <code class="text-xs text-zinc-500">{{ $variant->code }}</code>
                    <flux:heading size="lg">{{ $variant->name }}</flux:heading>
                    <flux:text class="mt-1">
                        @forelse ($variant->attributes as $attribute)
                            <span>{{ $attribute->type->name }}: {{ $attribute->value }}@if ($attribute->supplier_code) ({{ $attribute->supplier_code }})@endif</span>@if (! $loop->last) · @endif
                        @empty
                            {{ __('No attributes') }}
                        @endforelse
                    </flux:text>
                </div>
                @can('materials.contribute')
                    <div class="flex flex-wrap gap-2">
                        @foreach ($this->derivableTargets as $target)
                            <flux:button wire:click="derive({{ $variant->id }}, '{{ $target->slug }}')" size="sm" data-test="derive-{{ $target->slug }}">{{ __('Derive :target', ['target' => $target->name]) }}</flux:button>
                        @endforeach
                    </div>
                @endcan
            </div>

            @if ($variant->representations->isEmpty())
                <flux:text class="mt-4">{{ __('No representations yet.') }}</flux:text>
            @else
                <flux:table class="mt-4">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Target') }}</flux:table.column>
                        <flux:table.column>{{ __('Quality') }}</flux:table.column>
                        <flux:table.column>{{ __('Kind') }}</flux:table.column>
                        <flux:table.column>{{ __('Files') }}</flux:table.column>
                        <flux:table.column>{{ __('State') }}</flux:table.column>
                        <flux:table.column></flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($variant->representations->sortBy([['target.sort_order', 'asc'], ['id', 'desc']]) as $representation)
                            <flux:table.row :key="$representation->id" data-test="representation">
                                <flux:table.cell variant="strong">{{ $representation->target->name }}</flux:table.cell>
                                <flux:table.cell>{{ $representation->quality->name }}</flux:table.cell>
                                <flux:table.cell>{{ $representation->kind }}</flux:table.cell>
                                <flux:table.cell>{{ $representation->representationFiles->count() }}</flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" :color="match ($representation->review_state->value) { 'approved' => 'lime', 'rejected' => 'red', 'superseded' => 'zinc', default => 'amber' }" data-test="review-state">{{ $representation->review_state->label() }}</flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>
                                    @can('materials.review')
                                        @if ($representation->review_state->value === 'candidate')
                                            <flux:button wire:click="review({{ $representation->id }}, 'approved')" size="xs" variant="primary" data-test="approve">{{ __('Approve') }}</flux:button>
                                            <flux:button wire:click="review({{ $representation->id }}, 'rejected')" size="xs" variant="danger" data-test="reject">{{ __('Reject') }}</flux:button>
                                        @endif
                                    @endcan
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </div>
    @endforeach

    @can('materials.contribute')
        <form wire:submit="addVariant" class="flex flex-wrap items-end gap-3 rounded-xl border border-dashed border-neutral-300 p-5 dark:border-neutral-700">
            <flux:input wire:model="newColourway" :label="__('New colourway')" placeholder="Slate" data-test="new-colourway" />
            <flux:input wire:model="newColourwayCode" :label="__('Supplier colour code')" />
            <flux:button type="submit" size="sm" data-test="add-variant">{{ __('Add variant') }}</flux:button>
        </form>
    @endcan

    <div class="grid gap-6 lg:grid-cols-2">
        <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <flux:heading size="lg">{{ __('Versions') }}</flux:heading>
            @if ($this->versions->isEmpty())
                <flux:text class="mt-3">{{ __('Nothing published yet. Approve at least one representation, then publish.') }}</flux:text>
            @else
                <ul class="mt-3 divide-y divide-neutral-200 dark:divide-neutral-700">
                    @foreach ($this->versions as $version)
                        <li class="flex items-center justify-between gap-3 py-2" wire:key="version-{{ $version->id }}" data-test="version">
                            <div>
                                <span class="font-medium">v{{ $version->number }}</span>
                                <span class="text-xs text-zinc-500">· {{ $version->status->value }} · {{ trans_choice(':count representation|:count representations', $version->representations_count) }}</span>
                            </div>
                            @if ($version->isCurrent())
                                <flux:badge size="sm" color="lime">{{ __('Current') }}</flux:badge>
                            @elsecan('materials.publish')
                                <flux:button wire:click="makeCurrent({{ $version->id }})" size="xs" data-test="make-current">{{ __('Make current') }}</flux:button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700" data-test="visibility">
            <flux:heading size="lg">{{ __('Visibility') }}</flux:heading>
            <flux:text class="mt-1">{{ $material->visibility->label() }}</flux:text>
            @can('materials.publish')
                <div class="mt-3 flex gap-2">
                    <flux:button wire:click="setVisibility('library')" size="xs" :variant="$material->visibility->value === 'library' ? 'primary' : 'filled'" data-test="visibility-library">{{ __('Whole library') }}</flux:button>
                    <flux:button wire:click="setVisibility('restricted')" size="xs" :variant="$material->visibility->value === 'restricted' ? 'primary' : 'filled'" data-test="visibility-restricted">{{ __('Restricted') }}</flux:button>
                </div>
                <ul class="mt-4 space-y-1 text-sm">
                    @forelse ($material->grants()->with('grantee')->get() as $grant)
                        <li class="flex items-center justify-between gap-3" wire:key="grant-{{ $grant->id }}" data-test="grant">
                            <span>{{ class_basename($grant->grantee_type === 'drive' ? 'Drive' : ($grant->grantee_type === 'tenant' ? 'Tenant' : 'User')) }}: {{ $grant->grantee?->name ?? $grant->grantee_id }}</span>
                            <flux:button wire:click="revoke({{ $grant->id }})" size="xs" variant="ghost">{{ __('Revoke') }}</flux:button>
                        </li>
                    @empty
                        <li class="text-zinc-500">{{ __('No grants.') }}</li>
                    @endforelse
                </ul>
                <form wire:submit="grantUser" class="mt-3 flex items-end gap-2">
                    <flux:input wire:model="grantEmail" :label="__('Grant a user')" type="email" placeholder="colleague@example.com" data-test="grant-email" />
                    <flux:button type="submit" size="sm" data-test="grant-user">{{ __('Grant') }}</flux:button>
                </form>
                <form wire:submit="grantDrive" class="mt-3 flex items-end gap-2">
                    <flux:select wire:model="grantDrive" :label="__('Grant a drive')" :placeholder="__('Choose a drive')" data-test="grant-drive-select">
                        @foreach ($this->drives as $drive)
                            <flux:select.option value="{{ $drive->id }}">{{ $drive->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button type="submit" size="sm" data-test="grant-drive">{{ __('Grant') }}</flux:button>
                </form>
            @endcan
        </div>
    </div>

    <div class="rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
        <flux:heading size="lg">{{ __('Provenance') }}</flux:heading>
        @if ($this->timeline->isEmpty())
            <flux:text class="mt-3">{{ __('No events recorded.') }}</flux:text>
        @else
            <ol class="mt-3 space-y-2 text-sm">
                @foreach ($this->timeline as $event)
                    <li class="flex flex-wrap items-baseline gap-2" wire:key="event-{{ $event->id }}" data-test="provenance-event">
                        <span class="text-xs text-zinc-500">{{ $event->occurred_at->format('Y-m-d H:i') }}</span>
                        <span>{{ $event->describe() }}</span>
                        @if ($event->source) <flux:badge size="sm" color="zinc">{{ $event->source->name }}</flux:badge> @endif
                        @if ($event->outputs->isNotEmpty()) <span class="text-xs text-zinc-500">→ {{ $event->outputs->pluck('original_name')->filter()->implode(', ') }}</span> @endif
                        @if ($event->notes) <span class="text-xs italic text-zinc-500">{{ $event->notes }}</span> @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</section>
