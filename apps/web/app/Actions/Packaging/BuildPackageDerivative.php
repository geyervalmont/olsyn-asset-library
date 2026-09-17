<?php

namespace App\Actions\Packaging;

use App\Library\Derivatives\PackageDerivativeBuilder;
use App\Library\FileStore;
use App\Models\MapRole;
use App\Models\Package;
use App\Models\PackageDerivative;
use App\Models\QualityTier;
use App\Models\Target;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Materialise one reproducible consumer cache from the canonical USDZ. */
class BuildPackageDerivative
{
    public function __construct(
        private readonly PackageDerivativeBuilder $builder,
        private readonly FileStore $files,
    ) {}

    public function handle(Package $package, Target|string $target, QualityTier|string $quality): PackageDerivative
    {
        $target = $target instanceof Target ? $target : Target::fromSlug($target);
        $quality = $quality instanceof QualityTier ? $quality : QualityTier::fromSlug($quality);

        if (! $this->builder->available()) {
            throw new RuntimeException('No USD toolbox is available, so package derivatives cannot be built.');
        }

        if (! $this->builder->supports($target)) {
            throw new RuntimeException("No package converter supports [{$target->slug}].");
        }

        $converter = $this->builder->name().':'.$target->slug;
        $version = $this->builder->version();
        $existing = $this->existing($package, $target, $quality, $converter, $version);

        if ($existing !== null) {
            return $existing;
        }

        $built = $this->builder->build($package->loadMissing('variant'), $target, $quality);

        if ($built->assets === []) {
            throw new RuntimeException("The [{$target->slug}] converter produced no files.");
        }

        $stored = [];

        foreach ($built->assets as $asset) {
            $role = MapRole::fromSlug($asset->role);
            $file = $this->files->store($asset->contents, $asset->name, $asset->mimeType);
            $stored[] = [$file, $role, $asset->colourSpace ?? $file->colour_space ?? $role->colour_space];
        }

        try {
            return DB::transaction(function () use ($package, $target, $quality, $converter, $version, $built, $stored): PackageDerivative {
                $derivative = PackageDerivative::create([
                    'package_id' => $package->getKey(),
                    'target_id' => $target->getKey(),
                    'quality_tier_id' => $quality->getKey(),
                    'source_sha256' => $package->sha256,
                    'converter' => $converter,
                    'converter_version' => $version,
                    'losses' => $built->losses,
                    'built_at' => now(),
                ]);

                foreach ($stored as [$file, $role, $colourSpace]) {
                    $derivative->derivativeFiles()->create([
                        'file_id' => $file->getKey(),
                        'map_role_id' => $role->getKey(),
                        'colour_space' => $colourSpace,
                    ]);
                }

                return $derivative->load(['target', 'quality', 'derivativeFiles.file', 'derivativeFiles.role']);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->existing($package, $target, $quality, $converter, $version)
                ?? throw new RuntimeException('A package derivative raced with another build but could not be reloaded.');
        }
    }

    private function existing(Package $package, Target $target, QualityTier $quality, string $converter, string $version): ?PackageDerivative
    {
        return PackageDerivative::query()
            ->where('package_id', $package->getKey())
            ->where('target_id', $target->getKey())
            ->where('quality_tier_id', $quality->getKey())
            ->where('converter', $converter)
            ->where('converter_version', $version)
            ->with(['target', 'quality', 'derivativeFiles.file', 'derivativeFiles.role'])
            ->first();
    }
}
