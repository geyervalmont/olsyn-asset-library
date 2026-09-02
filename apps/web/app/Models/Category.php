<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $kind
 * @property string $code
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property int $sort_order
 */
#[Fillable(['kind', 'code', 'name', 'slug', 'description', 'sort_order'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    public const KIND_MATERIAL = 'material';

    protected static function booted(): void
    {
        static::saving(function (Category $category): void {
            $category->kind = $category->kind ?: self::KIND_MATERIAL;
            $category->code = strtoupper($category->code);
            $category->slug = $category->slug ?: Str::slug($category->name);
        });
    }

    /**
     * @return HasMany<Material, $this>
     */
    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }
}
