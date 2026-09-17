<?php

namespace Database\Factories;

use App\Models\Package;
use App\Models\PackageDerivative;
use App\Models\QualityTier;
use App\Models\Target;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PackageDerivative> */
class PackageDerivativeFactory extends Factory
{
    protected $model = PackageDerivative::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'package_id' => Package::factory(),
            'target_id' => fn () => Target::fromSlug('revit')->getKey(),
            'quality_tier_id' => fn () => QualityTier::fromSlug('2k')->getKey(),
            'source_sha256' => function (array $attributes): string {
                $package = $attributes['package_id'] ?? null;

                if ($package instanceof Package) {
                    return $package->sha256;
                }

                if (is_int($package) || is_string($package)) {
                    return (string) Package::query()->whereKey($package)->value('sha256');
                }

                return fake()->sha256();
            },
            'converter' => 'usd-toolbox:revit',
            'converter_version' => '0.1.0',
            'losses' => [],
            'built_at' => now(),
        ];
    }
}
