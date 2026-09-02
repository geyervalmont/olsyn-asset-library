<?php

namespace App\Models;

use App\Enums\ReviewState;
use App\Models\Concerns\HasProvenance;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The file set for one variant at one target and one quality. The key and the
 * files never change after creation; only review state and notes do. A better
 * result is a new representation that supersedes this one.
 *
 * @property int $id
 * @property int $variant_id
 * @property int $target_id
 * @property int $quality_tier_id
 * @property string $kind
 * @property ReviewState $review_state
 * @property int|null $reviewed_by_user_id
 * @property Carbon|null $reviewed_at
 * @property int|null $created_by_user_id
 * @property array<string, mixed>|null $metadata
 * @property string|null $notes
 * @property-read Variant $variant
 * @property-read Target $target
 * @property-read QualityTier $quality
 */
#[Fillable(['variant_id', 'target_id', 'quality_tier_id', 'kind', 'review_state', 'reviewed_by_user_id', 'reviewed_at', 'created_by_user_id', 'metadata', 'notes'])]
class Representation extends Model
{
    use HasProvenance;

    public const KIND_PBR_SET = 'pbr_set';

    public const KIND_IMAGE = 'image';

    public const KIND_PACKAGE = 'package';

    protected static function booted(): void
    {
        static::updating(function (Representation $representation): void {
            foreach (['variant_id', 'target_id', 'quality_tier_id', 'kind'] as $column) {
                if ($representation->isDirty($column)) {
                    throw new LogicException("Representation [{$column}] is immutable; create a new representation instead.");
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'review_state' => ReviewState::class,
            'metadata' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function isApproved(): bool
    {
        return $this->review_state === ReviewState::Approved;
    }

    /**
     * @param  Builder<Representation>  $query
     * @return Builder<Representation>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('review_state', ReviewState::Approved->value);
    }

    /**
     * @param  Builder<Representation>  $query
     * @return Builder<Representation>
     */
    public function scopeForKey(Builder $query, Variant $variant, Target $target, QualityTier $quality): Builder
    {
        return $query
            ->where('variant_id', $variant->getKey())
            ->where('target_id', $target->getKey())
            ->where('quality_tier_id', $quality->getKey());
    }

    public function fileFor(string $roleSlug): ?File
    {
        $role = MapRole::findBySlug($roleSlug);

        if ($role === null) {
            return null;
        }

        return $this->files()->wherePivot('map_role_id', $role->getKey())->first();
    }

    /**
     * Files keyed by map role slug.
     *
     * @return array<string, File>
     */
    public function filesByRole(): array
    {
        $files = [];

        $representationFiles = $this->representationFiles()
            ->with(['file', 'role'])
            ->join('map_roles', 'map_roles.id', '=', 'representation_files.map_role_id')
            ->orderBy('map_roles.sort_order')
            ->orderBy('map_roles.slug')
            ->select('representation_files.*')
            ->get();

        foreach ($representationFiles as $representationFile) {
            $files[$representationFile->role->slug] = $representationFile->file;
        }

        return $files;
    }

    /**
     * @return BelongsTo<Variant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    /**
     * @return BelongsTo<Target, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }

    /**
     * @return BelongsTo<QualityTier, $this>
     */
    public function quality(): BelongsTo
    {
        return $this->belongsTo(QualityTier::class, 'quality_tier_id');
    }

    /**
     * @return BelongsToMany<File, $this>
     */
    public function files(): BelongsToMany
    {
        return $this->belongsToMany(File::class, 'representation_files')->withPivot(['map_role_id', 'colour_space']);
    }

    /**
     * @return HasMany<RepresentationFile, $this>
     */
    public function representationFiles(): HasMany
    {
        return $this->hasMany(RepresentationFile::class);
    }

    /**
     * @return BelongsToMany<MaterialVersion, $this>
     */
    public function versions(): BelongsToMany
    {
        return $this->belongsToMany(MaterialVersion::class, 'material_version_representations');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
