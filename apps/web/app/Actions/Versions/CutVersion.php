<?php

namespace App\Actions\Versions;

use App\Enums\VersionStatus;
use App\Models\Material;
use App\Models\MaterialVersion;
use App\Models\Representation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Snapshot every approved representation of the material into a new draft
 * version. Nothing is copied: the version references the representations.
 */
class CutVersion
{
    public function handle(Material $material, ?User $createdBy = null, ?string $notes = null): MaterialVersion
    {
        return DB::transaction(function () use ($material, $createdBy, $notes): MaterialVersion {
            // One representation per (variant, target, quality): should two be
            // approved (an importer can do that), the fuller and newer one wins.
            $approved = Representation::query()
                ->approved()
                ->whereIn('variant_id', $material->variants()->select('id'))
                ->withCount('representationFiles')
                ->get()
                ->sortByDesc(fn (Representation $representation): array => [$representation->representation_files_count, $representation->getKey()])
                ->unique(fn (Representation $representation): string => $representation->variant_id.':'.$representation->target_id.':'.$representation->quality_tier_id)
                ->values();

            if ($approved->isEmpty()) {
                throw new LogicException("Material [{$material->code}] has no approved representations to version.");
            }

            /** @var MaterialVersion $version */
            $version = $material->versions()->create([
                'number' => (int) $material->versions()->max('number') + 1,
                'status' => VersionStatus::Draft,
                'notes' => $notes,
                'created_by_user_id' => $createdBy?->getKey(),
            ]);

            foreach ($approved as $representation) {
                $version->representations()->attach($representation->getKey(), [
                    'variant_id' => $representation->variant_id,
                    'target_id' => $representation->target_id,
                    'quality_tier_id' => $representation->quality_tier_id,
                ]);
            }

            return $version;
        });
    }
}
