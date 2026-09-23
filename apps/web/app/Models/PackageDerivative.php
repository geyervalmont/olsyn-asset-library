<?php

namespace App\Models;

use App\Models\Concerns\HasPermanentUuid;
use Database\Factories\PackageDerivativeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One reproducible consumer projection of an immutable USDZ package.
 *
 * The cache key is package + target + quality + converter + version. Rows and
 * their files are immutable: a converter change creates another generation.
 *
 * @property string $uuid
 * @property int $id
 * @property int $package_id
 * @property int $target_id
 * @property int $quality_tier_id
 * @property string $source_sha256
 * @property string $converter
 * @property string $converter_version
 * @property list<array<string, mixed>>|null $losses
 * @property Carbon $built_at
 * @property-read Package $package
 * @property-read Target $target
 * @property-read QualityTier $quality
 */
#[Fillable(['package_id', 'target_id', 'quality_tier_id', 'source_sha256', 'converter', 'converter_version', 'losses', 'built_at'])]
class PackageDerivative extends Model
{
    /** @use HasFactory<PackageDerivativeFactory> */
    use HasFactory, HasPermanentUuid;

    protected static function booted(): void
    {
        static::updating(function (PackageDerivative $derivative): never {
            throw new LogicException("Package derivative [{$derivative->getKey()}] is immutable; build a new cache generation instead.");
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['losses' => 'array', 'built_at' => 'datetime'];
    }

    /** @return array<string, File> */
    public function filesByRole(): array
    {
        $files = [];

        foreach ($this->derivativeFiles()->with(['file', 'role'])->get() as $entry) {
            $files[$entry->role->slug] = $entry->file;
        }

        return $files;
    }

    /** @return BelongsTo<Package, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /** @return BelongsTo<Target, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }

    /** @return BelongsTo<QualityTier, $this> */
    public function quality(): BelongsTo
    {
        return $this->belongsTo(QualityTier::class, 'quality_tier_id');
    }

    /** @return BelongsToMany<File, $this> */
    public function files(): BelongsToMany
    {
        return $this->belongsToMany(File::class, 'package_derivative_files')
            ->withPivot(['map_role_id', 'colour_space']);
    }

    /** @return HasMany<PackageDerivativeFile, $this> */
    public function derivativeFiles(): HasMany
    {
        return $this->hasMany(PackageDerivativeFile::class);
    }
}
