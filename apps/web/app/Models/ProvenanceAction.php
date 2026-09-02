<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A kind of provenance event. Data, not code: workers name their tools in
 * the event, never here.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property int $sort_order
 */
#[Fillable(['slug', 'name', 'description', 'sort_order'])]
class ProvenanceAction extends Model
{
    protected static function booted(): void
    {
        static::saving(function (ProvenanceAction $action): void {
            $action->slug = Str::slug($action->slug ?: $action->name, '_');
        });
    }

    public static function exists(string $slug): bool
    {
        return static::query()->where('slug', $slug)->exists();
    }
}
