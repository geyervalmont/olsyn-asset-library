<?php

namespace App\Models;

use App\Models\Concerns\HasPermanentUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A private staging area. Submission is not ingestion or publication.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $name
 * @property string $status
 * @property Carbon $expires_at
 * @property Carbon|null $submitted_at
 */
#[Fillable(['user_id', 'name', 'status', 'expires_at', 'submitted_at'])]
class DriveIntakeSession extends Model
{
    use HasPermanentUuid;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'submitted_at' => 'datetime'];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open')->where('expires_at', '>', now());
    }

    public function acceptsUploads(): bool
    {
        return $this->status === 'open' && $this->expires_at->isFuture();
    }

    /** @return HasMany<DriveIntakeFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(DriveIntakeFile::class);
    }
}
