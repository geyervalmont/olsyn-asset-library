<?php

namespace App\Models;

use App\Models\Concerns\IsRegistry;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property int|null $pixels
 * @property string|null $description
 * @property int $sort_order
 */
#[Fillable(['slug', 'name', 'pixels', 'description', 'sort_order'])]
class QualityTier extends Model
{
    use IsRegistry;

    /**
     * The seeded tier whose pixel size matches, or a custom tier created on
     * the fly, so arbitrary sizes never fall outside the registry.
     */
    public static function forPixels(int $pixels): self
    {
        return static::query()->firstOrCreate(
            ['pixels' => $pixels],
            ['slug' => $pixels.'px', 'name' => $pixels.' px', 'sort_order' => 1000],
        );
    }
}
