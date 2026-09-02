<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => Category::KIND_MATERIAL,
            'code' => 'Z'.strtoupper(fake()->unique()->lexify('??')),
            'name' => ucfirst(fake()->unique()->word()).' '.fake()->word(),
        ];
    }
}
