<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $user_id
 * @property string $event_id
 * @property string $device_id
 * @property string $kind
 * @property string $stage
 * @property string|null $code
 * @property string $version
 * @property Carbon $occurred_at
 * @property Carbon $received_at
 * @property array<string, int|string|null> $details
 * @property User $user
 */
#[Fillable(['user_id', 'device_id', 'event_id', 'kind', 'stage', 'code', 'version', 'occurred_at', 'received_at', 'details'])]
class DriveTelemetryEvent extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'received_at' => 'datetime', 'details' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
