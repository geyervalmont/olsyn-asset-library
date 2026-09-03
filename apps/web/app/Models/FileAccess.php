<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One read of a library file: who, through which channel, from where.
 *
 * @property int $id
 * @property int|null $file_id
 * @property string $channel
 * @property string $action
 * @property string|null $result
 * @property int|null $user_id
 * @property int|null $drive_id
 * @property string|null $request_id
 * @property string|null $principal
 * @property string|null $path
 * @property string|null $ip
 * @property string|null $user_agent
 * @property int|null $bytes
 * @property string|null $duration_ms
 * @property Carbon $accessed_at
 */
#[Fillable(['file_id', 'channel', 'action', 'result', 'user_id', 'drive_id', 'request_id', 'principal', 'path', 'ip', 'user_agent', 'bytes', 'duration_ms', 'accessed_at'])]
class FileAccess extends Model
{
    public $timestamps = false;

    public const CHANNEL_WEB = 'web';

    public const CHANNEL_PRISMFS = 'prismfs';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['accessed_at' => 'datetime'];
    }

    /**
     * Who read: the signed-in user for web downloads, the drive principal otherwise.
     */
    public function who(): string
    {
        if ($this->user !== null) {
            return $this->user->name;
        }

        return $this->principal ?? __('unknown');
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Drive, $this>
     */
    public function drive(): BelongsTo
    {
        return $this->belongsTo(Drive::class);
    }
}
