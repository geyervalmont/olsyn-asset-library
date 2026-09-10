<?php

namespace Database\Factories;

use App\Models\Definition;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Definition>
 */
class DefinitionFactory extends Factory
{
    protected $model = Definition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'variant_id' => Variant::factory(),
            'generator' => 'paint',
            'generator_version' => '1.0.0',
            'parameters' => [
                'seed' => fake()->numberBetween(1, 100000),
                'output' => ['width_px' => 1024, 'height_px' => 1024, 'width_mm' => 1000, 'height_mm' => 1000],
                'recipe' => ['colour' => fake()->hexColor(), 'roughness' => 0.62, 'variation' => 0.02, 'texture_depth' => 0.1],
            ],
        ];
    }
}
