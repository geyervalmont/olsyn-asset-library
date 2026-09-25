<?php

namespace App\Livewire\Materials;

use App\Actions\Clients\IssueClientCommand;
use App\Enums\CommandType;
use App\Enums\ReviewState;
use App\Library\Previews\MaterialPreviews;
use App\Livewire\ConsumerComponent;
use App\Models\Material;
use App\Models\QualityTier;
use App\Models\Target;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Isolate;
use Livewire\Attributes\Locked;

/**
 * @property-read Material|null $quickMaterial
 * @property-read array{variants: list<array{id: int, code: string, name: string, hex: string, image: string|null}>, active: int} $quickCard
 */
#[Isolate]
class QuickView extends ConsumerComponent
{
    public string $quick = '';

    public ?int $quickVariantId = null;

    #[Locked]
    public int $requestId = 0;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('materials.view'), 403);
        $this->quick = (string) request()->query('material', '');
        $this->mountConsumers();
    }

    public function hydrate(): void
    {
        abort_unless(auth()->user()?->can('materials.view'), 403);
    }

    public function openQuick(string $code, ?int $variantId = null, int $requestId = 0): bool
    {
        $this->requestId = $requestId;
        $this->quick = $code;
        $this->quickVariantId = $variantId;
        $this->consumerCommandId = null;
        unset($this->quickMaterial, $this->quickCard, $this->quickTargets, $this->quickViewerSets, $this->consumerCommand);

        return $this->quickMaterial !== null;
    }

    public function closeQuick(): void
    {
        $this->quick = '';
        $this->quickVariantId = null;
        $this->consumerCommandId = null;
        unset($this->quickMaterial, $this->quickCard, $this->quickTargets, $this->quickViewerSets, $this->consumerCommand);
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
            ->whereIn('id', array_column($this->quickCard['variants'], 'id'))
            ->with([
                'representations' => fn ($query) => $query
                    ->where('target_id', Target::canonical()?->id)
                    ->whereIn('review_state', [ReviewState::Approved, ReviewState::Candidate])
                    ->orderByRaw("case when review_state = 'approved' then 0 else 1 end")
                    ->orderByDesc(QualityTier::query()->select('pixels')->whereColumn('quality_tiers.id', 'representations.quality_tier_id'))
                    ->orderByDesc('id')->limit(1),
                'representations.quality', 'representations.representationFiles.role', 'representations.representationFiles.file',
            ])
            ->get();

        $variants->each(fn ($variant) => $variant->setRelation('material', $material));

        return app(MaterialPreviews::class)->viewerSets($material, $variants, previewSize: 1024);
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
        if ($this->consumerBlockedReason() !== null) {
            return $this->consumerBlockedReason();
        }

        return $this->quickMaterial?->current_version_id === null
            ? (string) __('Publish a version first to apply this material.')
            : null;
    }

    public function applyToConsumer(int $variantId, IssueClientCommand $issue): void
    {
        $material = $this->quickMaterial;
        $variant = $material?->variants()->whereKey($variantId)->first();
        $session = $this->selectedConsumer();

        if ($material === null || $variant === null || $session === null || $this->applyBlockedReason() !== null) {
            Flux::toast(variant: 'warning', text: $this->applyBlockedReason() ?? __('That colourway is no longer available.'));

            return;
        }

        $this->consumerSessionId = $session->id;
        $this->consumerCommandId = $issue->handle($session, auth()->user(), CommandType::Apply, [
            'variant' => $variant->code,
            'variant_uuid' => $variant->uuid,
            'material_version' => $material->currentVersion->number,
            'material' => $material->code,
        ])->id;

        unset($this->consumerCommand);
    }

    public function render(): View
    {
        return view('livewire.materials.quick-view');
    }
}
