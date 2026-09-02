<?php

namespace App\Models;

use App\Enums\VersionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * An immutable snapshot of approved representations. Cutting a new version
 * re-references representations that did not change.
 *
 * @property int $id
 * @property int $material_id
 * @property int $number
 * @property VersionStatus $status
 * @property string|null $notes
 * @property int|null $created_by_user_id
 * @property int|null $published_by_user_id
 * @property Carbon|null $published_at
 * @property-read Material $material
 */
#[Fillable(['number', 'status', 'notes', 'created_by_user_id', 'published_by_user_id', 'published_at'])]
class MaterialVersion extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VersionStatus::class,
            'published_at' => 'datetime',
        ];
    }

    public function isCurrent(): bool
    {
        return (string) $this->material->current_version_id === (string) $this->getKey();
    }

    /**
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * @return BelongsToMany<Representation, $this>
     */
    public function representations(): BelongsToMany
    {
        return $this->belongsToMany(Representation::class, 'material_version_representations')
            ->withPivot(['variant_id', 'target_id', 'quality_tier_id']);
    }

    /**
     * The representation this version pins for a variant, target and quality.
     */
    public function representationFor(Variant $variant, Target $target, QualityTier $quality): ?Representation
    {
        return $this->representations()
            ->wherePivot('variant_id', $variant->getKey())
            ->wherePivot('target_id', $target->getKey())
            ->wherePivot('quality_tier_id', $quality->getKey())
            ->first();
    }
}
