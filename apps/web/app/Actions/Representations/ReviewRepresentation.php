<?php

namespace App\Actions\Representations;

use App\Actions\Provenance\RecordProvenance;
use App\Enums\ReviewState;
use App\Jobs\RenderPreview;
use App\Models\Representation;
use App\Models\StudioRevision;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Approve or reject a candidate. Approval supersedes any earlier approved
 * representation for the same variant, target and quality, so exactly one
 * approved representation exists per key.
 */
class ReviewRepresentation
{
    public function __construct(private readonly RecordProvenance $provenance) {}

    public function handle(Representation $representation, ReviewState $decision, ?User $reviewer = null, ?string $notes = null): Representation
    {
        if (! in_array($decision, [ReviewState::Approved, ReviewState::Rejected], true)) {
            throw new InvalidArgumentException('A review decision is approved or rejected.');
        }

        return DB::transaction(function () use ($representation, $decision, $reviewer, $notes): Representation {
            Variant::query()->whereKey($representation->variant_id)->lockForUpdate()->firstOrFail();
            if ($decision === ReviewState::Approved) {
                $studio = StudioRevision::query()->where('representation_id', $representation->id)->first();
                $previous = Representation::query()
                    ->where('variant_id', $representation->variant_id)
                    ->where('target_id', $representation->target_id);
                // A Studio repair is a replacement surface, including its scale.
                // Keeping old higher-resolution maps would silently reintroduce
                // the previous surface when the package creates its LODs.
                if ($studio === null || ($representation->metadata['studio_destination'] ?? '') !== 'improve') {
                    $previous->where('quality_tier_id', $representation->quality_tier_id);
                }
                $previous->approved()->whereKeyNot($representation->getKey())
                    ->update(['review_state' => ReviewState::Superseded->value]);
                if ($studio !== null) {
                    $representation->variant->update([
                        'tile_width_mm' => $studio->document['width_mm'],
                        'tile_height_mm' => $studio->document['height_mm'],
                    ]);
                }
            }

            $representation->forceFill([
                'review_state' => $decision,
                'reviewed_by_user_id' => $reviewer?->getKey(),
                'reviewed_at' => now(),
                'notes' => $notes ?? $representation->notes,
            ])->save();

            $this->provenance->handle(
                $representation,
                $decision->value,
                $reviewer,
                inputs: array_values($representation->files->all()),
                notes: $notes,
            );

            $representation->refresh();
            $this->renderPreview($representation);

            return $representation;
        });
    }

    /**
     * A newly approved canonical set gets a swatch rendered, unless one
     * already exists for exactly these inputs.
     */
    private function renderPreview(Representation $representation): void
    {
        if (! config('opal.previews.auto_render') || ! $representation->isApproved() || ! $representation->target->is_canonical) {
            return;
        }

        if ($representation->fileFor('base_color')?->isImage() !== true) {
            return;
        }

        if (RenderPreview::renderedFor($representation->variant, RenderPreview::inputHash($representation)) !== null) {
            return;
        }

        RenderPreview::forVariant($representation->variant);
    }
}
