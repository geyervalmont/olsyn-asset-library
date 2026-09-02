<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A retired code that still resolves to its record, so paths and platform
 * names issued before a recode keep working.
 *
 * @property int $id
 * @property string $aliasable_type
 * @property int $aliasable_id
 * @property string $code
 * @property string|null $reason
 */
#[Fillable(['code', 'reason'])]
class Alias extends Model
{
    /**
     * @return MorphTo<Model, $this>
     */
    public function aliasable(): MorphTo
    {
        return $this->morphTo();
    }
}
