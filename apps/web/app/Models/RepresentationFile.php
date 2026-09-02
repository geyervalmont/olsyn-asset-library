<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $representation_id
 * @property int $file_id
 * @property int $map_role_id
 * @property string|null $colour_space
 * @property-read File $file
 * @property-read MapRole $role
 */
#[Fillable(['file_id', 'map_role_id', 'colour_space'])]
class RepresentationFile extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<Representation, $this>
     */
    public function representation(): BelongsTo
    {
        return $this->belongsTo(Representation::class);
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /**
     * @return BelongsTo<MapRole, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(MapRole::class, 'map_role_id');
    }
}
