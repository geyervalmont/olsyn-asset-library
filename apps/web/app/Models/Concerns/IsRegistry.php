<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Small seeded vocabularies looked up by slug.
 */
trait IsRegistry
{
    public static function bootIsRegistry(): void
    {
        static::saving(function (self $model): void {
            $model->slug = Str::slug($model->slug ?: $model->name, '_');
        });
    }

    public static function findBySlug(string $slug): ?static
    {
        return static::query()->where('slug', Str::slug($slug, '_'))->first();
    }

    public static function fromSlug(string $slug): static
    {
        return static::findBySlug($slug) ?? throw new InvalidArgumentException(sprintf(
            'Unknown %s [%s].',
            Str::headline(class_basename(static::class)),
            $slug,
        ));
    }
}
