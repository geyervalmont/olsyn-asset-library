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
 * @property int $file_id
 * @property string $channel
 * @property string $action
 * @property int|null $user_id
 * @property int|null $drive_id
 * @property string|null $principal
 * @property string|null $path
 * @property string|null $ip
 * @property string|null $user_agent
 * @property int|null $bytes
 * @property Carbon $accessed_at
 */
#[Fillable(['file_id', 'channel', 'action', 'user_id', 'drive_id', 'principal', 'path', 'ip', 'user_agent', 'bytes', 'accessed_at'])]
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
