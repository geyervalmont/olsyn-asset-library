<?php

namespace App\Actions\Versions;

use App\Actions\Packaging\AssembleBuildRequest;
use App\Enums\ReviewState;
use App\Enums\VersionStatus;
use App\Models\Material;
use App\Models\MaterialVersion;
use App\Models\Package;
use App\Models\Target;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Snapshot one immutable canonical USDZ package per publishable variant.
 * Nothing is copied: the version pins the package revision and its already
 * verified consumer projections can be regenerated from that package.
 */
class CutVersion
{
    public function __construct(private readonly AssembleBuildRequest $assemble) {}

    public function handle(Material $material, ?User $createdBy = null, ?string $notes = null): MaterialVersion
    {
        return DB::transaction(function () use ($material, $createdBy, $notes): MaterialVersion {
            $canonical = Target::canonical() ?? throw new LogicException('No canonical target is defined.');
            $variants = $material->variants()
                ->whereHas('representations', fn ($query) => $query
                    ->where('review_state', ReviewState::Approved)
                    ->where('target_id', $canonical->getKey()))
                ->orderBy('id')
                ->get();

            if ($variants->isEmpty()) {
                throw new LogicException("Material [{$material->code}] has no approved canonical material to package.");
            }

            $packages = [];

            foreach ($variants as $variant) {
                $request = $this->assemble->handle($variant);
                $package = Package::query()
                    ->where('variant_id', $variant->getKey())
                    ->where('request_digest', $request->digest())
                    ->orderByDesc('revision')
                    ->first();

                if ($package === null) {
                    throw new LogicException("Variant [{$variant->code}] has no up-to-date canonical USDZ package.");
                }

                foreach ((array) config('opal.publication_targets', ['revit']) as $targetSlug) {
                    $target = Target::fromSlug((string) $targetSlug);
                    $ready = $package->derivatives()
                        ->where('target_id', $target->getKey())
                        ->where('source_sha256', $package->sha256)
                        ->exists();

                    if (! $ready) {
                        throw new LogicException("Package [{$variant->code} r{$package->revision}] has no ready [{$target->slug}] projection.");
                    }
                }

                $packages[] = $package;
            }

            /** @var MaterialVersion $version */
            $version = $material->versions()->create([
                'number' => (int) $material->versions()->max('number') + 1,
                'status' => VersionStatus::Draft,
                'notes' => $notes,
                'created_by_user_id' => $createdBy?->getKey(),
            ]);

            foreach ($packages as $package) {
                $version->packages()->attach($package->getKey(), [
                    'variant_id' => $package->variant_id,
                ]);
            }

            return $version;
        });
    }
}
