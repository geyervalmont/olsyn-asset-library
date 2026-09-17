<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One role in a package-derived consumer cache.
 *
 * @property int $id
 * @property int $package_derivative_id
 * @property int $file_id
 * @property int $map_role_id
 * @property string|null $colour_space
 * @property-read File $file
 * @property-read MapRole $role
 */
#[Fillable(['file_id', 'map_role_id', 'colour_space'])]
class PackageDerivativeFile extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(function (PackageDerivativeFile $file): never {
            throw new LogicException("Package derivative file [{$file->getKey()}] is immutable; build a new cache generation instead.");
        });
    }

    /** @return BelongsTo<PackageDerivative, $this> */
    public function derivative(): BelongsTo
    {
        return $this->belongsTo(PackageDerivative::class, 'package_derivative_id');
    }

    /** @return BelongsTo<File, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /** @return BelongsTo<MapRole, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(MapRole::class, 'map_role_id');
    }
}
