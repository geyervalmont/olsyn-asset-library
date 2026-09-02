<?php

namespace App\Models;

use App\Models\Concerns\IsRegistry;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An output format a consumer reads: pbr (canonical), revit, omniverse, preview.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property bool $is_canonical
 * @property int $sort_order
 */
#[Fillable(['slug', 'name', 'description', 'is_canonical', 'sort_order'])]
class Target extends Model
{
    use IsRegistry;

    public static function canonical(): ?self
    {
        return static::query()->where('is_canonical', true)->orderBy('sort_order')->first();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_canonical' => 'boolean'];
    }
}
