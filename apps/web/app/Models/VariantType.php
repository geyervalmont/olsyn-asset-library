<?php

namespace App\Models;

use Database\Factories\VariantTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A kind of variation a material can have: colourway, saturation, finish,
 * pattern, format, or anything an admin adds later.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $applies_to
 * @property string|null $description
 * @property int $sort_order
 */
#[Fillable(['slug', 'name', 'applies_to', 'description', 'sort_order'])]
class VariantType extends Model
{
    /** @use HasFactory<VariantTypeFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (VariantType $type): void {
            $type->slug = Str::slug($type->slug ?: $type->name);
        });
    }

    public static function findBySlug(string $slug): ?self
    {
        return static::query()->where('slug', Str::slug($slug))->first();
    }
}
