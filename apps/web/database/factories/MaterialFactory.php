<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Material;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Material>
 */
class MaterialFactory extends Factory
{
    protected $model = Material::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->word()).' '.fake()->word(),
            'category_id' => Category::factory(),
            'supplier_id' => Supplier::factory(),
        ];
    }

    public function inHouse(): static
    {
        return $this->state(fn (array $attributes) => ['supplier_id' => null]);
    }
}
