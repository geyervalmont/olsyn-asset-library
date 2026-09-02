<?php

namespace App\Actions\Representations;

use App\Enums\ReviewState;
use App\Models\File;
use App\Models\MapRole;
use App\Models\QualityTier;
use App\Models\Representation;
use App\Models\Target;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreateRepresentation
{
    /**
     * Create a candidate representation from files keyed by map role slug.
     *
     * @param  array<string, File>  $files
     * @param  array<string, mixed>|null  $metadata
     */
    public function handle(
        Variant $variant,
        Target|string $target,
        QualityTier|string|int $quality,
        array $files,
        ?string $kind = null,
        ?User $createdBy = null,
        ?array $metadata = null,
        ?string $notes = null,
    ): Representation {
        if ($files === []) {
            throw new InvalidArgumentException('A representation needs at least one file.');
        }

        $target = $target instanceof Target ? $target : Target::fromSlug($target);
        $quality = match (true) {
            $quality instanceof QualityTier => $quality,
            is_int($quality) => QualityTier::forPixels($quality),
            default => QualityTier::fromSlug($quality),
        };

        $roles = [];

        foreach (array_keys($files) as $slug) {
            $roles[$slug] = MapRole::fromSlug($slug);
        }

        return DB::transaction(function () use ($variant, $target, $quality, $files, $roles, $kind, $createdBy, $metadata, $notes): Representation {
            $representation = Representation::create([
                'variant_id' => $variant->getKey(),
                'target_id' => $target->getKey(),
                'quality_tier_id' => $quality->getKey(),
                'kind' => $kind ?? $this->inferKind(array_keys($files)),
                'review_state' => ReviewState::Candidate,
                'created_by_user_id' => $createdBy?->getKey(),
                'metadata' => $metadata,
                'notes' => $notes,
            ]);

            foreach ($files as $slug => $file) {
                $representation->representationFiles()->create([
                    'file_id' => $file->getKey(),
                    'map_role_id' => $roles[$slug]->getKey(),
                    'colour_space' => $file->colour_space ?? $roles[$slug]->colour_space,
                ]);
            }

            return $representation->load('files');
        });
    }

    /**
     * @param  list<string>  $roleSlugs
     */
    public function inferKind(array $roleSlugs): string
    {
        $packageRoles = ['mdl', 'usd', 'rvt', 'package'];

        if (array_intersect($roleSlugs, $packageRoles) !== []) {
            return Representation::KIND_PACKAGE;
        }

        return count($roleSlugs) === 1 ? Representation::KIND_IMAGE : Representation::KIND_PBR_SET;
    }
}
