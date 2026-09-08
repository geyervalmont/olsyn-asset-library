<?php

namespace Database\Factories;

use App\Models\Package;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Package>
 */
class PackageFactory extends Factory
{
    protected $model = Package::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $digest = hash('sha256', fake()->unique()->uuid());

        return [
            'variant_id' => Variant::factory(),
            'revision' => 1,
            'object_key' => 'packages/'.substr($digest, 0, 2).'/'.$digest.'.usdz',
            'sha256' => $digest,
            'bytes' => fake()->numberBetween(1_000_000, 400_000_000),
            'tiers' => ['preview', '1k', '2k', '4k'],
            'builder' => 'materials-toolbox',
            'builder_version' => '0.1.0',
            'losses' => [],
            'built_at' => now(),
        ];
    }
}
