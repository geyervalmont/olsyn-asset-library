<?php

namespace Database\Factories;

use App\Models\File;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<File>
 */
class FileFactory extends Factory
{
    protected $model = File::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $sha256 = hash('sha256', fake()->unique()->uuid());

        return [
            'sha256' => $sha256,
            'disk' => config('opal.files_disk'),
            'object_key' => sprintf('%s/%s/%s/%s.png', config('opal.files_prefix'), substr($sha256, 0, 2), substr($sha256, 2, 2), $sha256),
            'kind' => 'image',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'original_name' => fake()->word().'.png',
            'bytes' => fake()->numberBetween(1_000, 5_000_000),
            'width_px' => 2048,
            'height_px' => 2048,
            'colour_space' => 'srgb',
            'bit_depth' => 8,
        ];
    }
}
