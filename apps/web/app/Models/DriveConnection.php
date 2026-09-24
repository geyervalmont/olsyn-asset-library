<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @property User $user
 * @property int $id
 * @property int $user_id
 * @property string $device_id
 * @property int|null $token_id
 * @property string $machine
 * @property string|null $version
 * @property string $state
 * @property string|null $mount_path
 * @property string|null $error_code
 * @property Carbon $last_seen_at
 */
#[Fillable(['user_id', 'device_id', 'token_id', 'machine', 'version', 'state', 'mount_path', 'error_code', 'last_seen_at'])]
class DriveConnection extends Model
{
    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isLive(): bool
    {
        return $this->last_seen_at->gte(now()->subSeconds(90)) && $this->state !== 'offline'
            && $this->token_id !== null && PersonalAccessToken::query()->whereKey($this->token_id)
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
    }
}
