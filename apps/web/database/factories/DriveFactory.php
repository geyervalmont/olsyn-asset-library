<?php

namespace Database\Factories;

use App\Models\Drive;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Drive>
 */
class DriveFactory extends Factory
{
    protected $model = Drive::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->word()).' drive',
            'root_path' => '/materials',
        ];
    }
}
