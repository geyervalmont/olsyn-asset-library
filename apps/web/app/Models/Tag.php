<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 */
#[Fillable(['name', 'slug'])]
class Tag extends Model
{
    protected static function booted(): void
    {
        static::saving(function (Tag $tag): void {
            $tag->slug = Str::slug($tag->slug ?: $tag->name);
        });
    }

    /**
     * @return MorphToMany<Material, $this>
     */
    public function materials(): MorphToMany
    {
        return $this->morphedByMany(Material::class, 'taggable');
    }
}
