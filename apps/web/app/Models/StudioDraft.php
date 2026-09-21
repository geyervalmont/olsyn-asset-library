<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $user_id
 * @property string $name
 * @property string $state
 * @property int|null $head_id
 * @property int|null $source_representation_id
 */
class StudioDraft extends Model
{
    protected $guarded = [];

    /** @param Builder<StudioDraft> $query
     * @return Builder<StudioDraft>
     */
    public function scopeOwned(Builder $query): Builder
    {
        return $query->where('user_id', auth()->id())->where('tenant_id', Tenant::current()?->getKey() ?? -1);
    }

    /** @return HasMany<StudioRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(StudioRevision::class);
    }

    /** @return BelongsTo<StudioRevision, $this> */
    public function head(): BelongsTo
    {
        return $this->belongsTo(StudioRevision::class, 'head_id');
    }
}
