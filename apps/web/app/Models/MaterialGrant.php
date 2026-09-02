<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int $material_id
 * @property string $grantee_type
 * @property int $grantee_id
 * @property int|null $granted_by_user_id
 * @property-read Material $material
 */
#[Fillable(['grantee_type', 'grantee_id', 'granted_by_user_id'])]
class MaterialGrant extends Model
{
    /**
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function grantee(): MorphTo
    {
        return $this->morphTo();
    }
}
