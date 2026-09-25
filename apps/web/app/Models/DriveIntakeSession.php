<?php

namespace App\Models;

use App\Library\Drives\DriveLayout;
use App\Models\Concerns\HasPermanentUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property int|null $tenant_id
 * @property bool $is_inbox
 * @property Carbon|null $expires_at
 * @property Carbon|null $submitted_at
 */
#[Fillable(['user_id', 'name', 'status', 'expires_at', 'submitted_at', 'tenant_id', 'is_inbox'])]
class DriveIntakeSession extends Model
{
    use HasPermanentUuid;

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['is_inbox' => 'boolean', 'expires_at' => 'datetime', 'submitted_at' => 'datetime'];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function acceptsUploads(): bool
    {
        return $this->status === 'open' && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * Drafts are private. Explicitly submitted batches can be reviewed by the
     * current workspace's reviewers. Legacy unscoped batches stay owner-only.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user): void {
            $query->where(function (Builder $own) use ($user): void {
                $own->where('user_id', $user->id)->where(fn ($scope) => $scope
                    ->whereNull('tenant_id')->orWhere('tenant_id', Tenant::current()?->id));
            });
            if (Tenant::current() !== null && $user->can('materials.review')) {
                $query->orWhere(fn ($shared) => $shared->where('tenant_id', Tenant::current()->id)->where('status', 'submitted'));
            }
        });
    }

    public function drivePath(): string
    {
        return $this->is_inbox ? DriveLayout::UPLOAD : '/Incoming/'.$this->uuid;
    }

    public function displayStatus(): string
    {
        return match (true) {
            $this->status === 'submitted' => 'Queued for review',
            $this->status === 'cancelled' => 'Closed',
            ! $this->acceptsUploads() => 'Expired',
            default => 'Receiving files',
        };
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<DriveIntakeFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(DriveIntakeFile::class);
    }
}
