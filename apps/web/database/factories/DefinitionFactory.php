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
            // A paint: a measured colour and a sheen, which is the whole
            // definition of a material that has no textures at all.
            'parameters' => [
                'l' => fake()->randomFloat(2, 0, 100),
                'a' => fake()->randomFloat(2, -60, 60),
                'b' => fake()->randomFloat(2, -60, 60),
                'sheen' => fake()->randomElement(['matt', 'low', 'semi_gloss', 'gloss']),
            ],
        ];
    }
}
