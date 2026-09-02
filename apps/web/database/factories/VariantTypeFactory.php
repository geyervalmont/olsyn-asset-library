<?php

namespace Database\Factories;

use App\Models\VariantType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VariantType>
 */
class VariantTypeFactory extends Factory
{
    protected $model = VariantType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'slug' => $name,
            'name' => ucfirst($name),
        ];
    }
}
