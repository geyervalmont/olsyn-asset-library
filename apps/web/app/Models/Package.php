<?php

namespace App\Models;

use Database\Factories\PackageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One built USDZ: the shading graph, its textures at every tier, and the
 * provenance that cannot be recomputed, in a single self-contained file.
 *
 * Rows are immutable. A rebuild is a new revision rather than an edit, so a
 * reference to a package can never resolve to different bytes than it did when
 * it was taken — which is the property that makes the archive trustworthy as
 * the library rather than merely a cache of it.
 *
 * @property int $id
 * @property int $variant_id
 * @property int $revision
 * @property string $object_key
 * @property string $sha256
 * @property string|null $request_digest
 * @property int $bytes
 * @property list<string> $tiers
 * @property string|null $builder
 * @property string|null $builder_version
 * @property list<array<string, mixed>>|null $losses
 * @property Carbon|null $built_at
 * @property-read Variant $variant
 */
#[Fillable(['revision', 'object_key', 'sha256', 'request_digest', 'bytes', 'tiers', 'builder', 'builder_version', 'losses', 'built_at'])]
class Package extends Model
{
    /** @use HasFactory<PackageFactory> */
    use HasFactory;

    /**
     * Losses are recorded, not prevented: a conversion that drops subsurface
     * targeting glTF is correct behaviour. Null means the build predates loss
     * reporting; an empty list means it was assessed and lost nothing.
     */
    public function isLossless(): bool
    {
        return $this->losses === [];
    }

    public function hasTier(string $tier): bool
    {
        return in_array($tier, $this->tiers, true);
    }

    /**
     * @return BelongsTo<Variant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tiers' => 'array',
            'losses' => 'array',
            'built_at' => 'datetime',
        ];
    }
}
