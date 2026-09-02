<?php

namespace App\Models;

use App\Models\Concerns\IsRegistry;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A consumer of materials and the target it reads by default.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property int|null $target_id
 * @property string|null $description
 * @property int $sort_order
 * @property-read Target|null $target
 */
#[Fillable(['slug', 'name', 'target_id', 'description', 'sort_order'])]
class Platform extends Model
{
    use IsRegistry;

    /**
     * @return BelongsTo<Target, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }
}
