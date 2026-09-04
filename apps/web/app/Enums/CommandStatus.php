<?php

namespace App\Enums;

enum CommandStatus: string
{
    case Queued = 'queued';
    case Acked = 'acked';
    case Done = 'done';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Acked => 'Received by Revit',
            self::Done => 'Done',
            self::Failed => 'Failed',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Done, self::Failed], true);
    }
}
