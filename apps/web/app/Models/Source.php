<?php

namespace App\Models;

use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Where files come from and what is known about the terms they came with.
 * Licence decisions are derived from provenance, so terms stay descriptive.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $kind
 * @property int|null $supplier_id
 * @property string|null $url
 * @property string|null $licence_url
 * @property array<string, mixed>|null $terms
 * @property string|null $notes
 */
#[Fillable(['name', 'slug', 'kind', 'supplier_id', 'url', 'licence_url', 'terms', 'notes'])]
class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Source $source): void {
            $source->slug = $source->slug ?: Str::slug($source->name);
            $source->kind = $source->kind ?: 'unknown';
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'terms' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
