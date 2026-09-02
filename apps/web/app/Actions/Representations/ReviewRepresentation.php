<?php

namespace App\Actions\Representations;

use App\Actions\Provenance\RecordProvenance;
use App\Enums\ReviewState;
use App\Models\Representation;
use App\Models\User;
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
            if ($decision === ReviewState::Approved) {
                Representation::query()
                    ->forKey($representation->variant, $representation->target, $representation->quality)
                    ->approved()
                    ->whereKeyNot($representation->getKey())
                    ->update(['review_state' => ReviewState::Superseded->value]);
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

            return $representation->refresh();
        });
    }
}
