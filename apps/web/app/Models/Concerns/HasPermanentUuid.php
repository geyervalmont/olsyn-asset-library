<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;
use LogicException;

/** Permanent public identity; integer foreign keys remain internal. */
trait HasPermanentUuid
{
    public static function bootHasPermanentUuid(): void
    {
        static::creating(function ($model): void {
            $model->uuid ??= (string) Str::uuid7();
            if (! Str::isUuid($model->uuid, version: 7)) {
                throw new LogicException('A permanent OPAL identity must be UUIDv7.');
            }
        });
        static::updating(function ($model): void {
            if ($model->isDirty('uuid')) {
                throw new LogicException('OPAL UUIDs are immutable.');
            }
        });
    }
}
