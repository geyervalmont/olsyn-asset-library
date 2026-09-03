<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Ledger of legacy corpus files: what was hashed and stored, so a rerun
 * skips unchanged files and retries failed ones.
 *
 * @property int $id
 * @property string $source_path
 * @property int|null $bytes
 * @property Carbon|null $mtime
 * @property string|null $sha256
 * @property int|null $file_id
 * @property string $status
 * @property string|null $error
 * @property Carbon|null $processed_at
 */
#[Fillable(['source_path', 'bytes', 'mtime', 'sha256', 'file_id', 'status', 'error', 'processed_at'])]
class LegacyFileIngest extends Model
{
    public const INGESTED = 'ingested';

    public const FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['mtime' => 'datetime', 'processed_at' => 'datetime'];
    }

    /**
     * Whether the ledger already covers this exact file on disk.
     */
    public function matches(int $bytes, int $mtime): bool
    {
        return $this->status === self::INGESTED
            && $this->file_id !== null
            && $this->bytes === $bytes
            && $this->mtime?->getTimestamp() === $mtime;
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
