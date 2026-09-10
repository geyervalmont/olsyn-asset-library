<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A model-generated vector for any library record. Vectors are replaceable
 * indexes, not source assets; the inputs and model identity make every value
 * reproducible and auditable.
 *
 * @property int $id
 * @property string $embeddable_type
 * @property int $embeddable_id
 * @property string $kind
 * @property string $provider
 * @property string $model
 * @property int $dimensions
 * @property string $source_digest
 * @property string $source_text
 * @property int|null $image_file_id
 * @property string $embedding
 * @property array<string, mixed>|null $metadata
 */
#[Fillable([
    'embeddable_type', 'embeddable_id', 'kind', 'provider', 'model', 'dimensions', 'source_digest', 'source_text',
    'image_file_id', 'embedding', 'metadata',
])]
class Embedding extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dimensions' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function embeddable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function imageFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'image_file_id');
    }
}
