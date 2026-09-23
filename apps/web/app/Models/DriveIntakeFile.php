<?php

namespace App\Models;

use App\Models\Concerns\HasPermanentUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $drive_intake_session_id
 * @property string $path
 * @property string $path_hash
 * @property int $bytes
 * @property string $sha256
 * @property string $disk
 * @property string $object_key
 * @property Carbon|null $uploaded_at
 */
#[Fillable(['path', 'path_hash', 'bytes', 'sha256', 'disk', 'object_key', 'uploaded_at'])]
class DriveIntakeFile extends Model
{
    use HasPermanentUuid;

    protected function casts(): array
    {
        return ['bytes' => 'integer', 'uploaded_at' => 'datetime'];
    }
}
