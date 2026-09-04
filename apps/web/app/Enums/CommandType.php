<?php

namespace App\Enums;

enum CommandType: string
{
    case Apply = 'apply';
    case Sync = 'sync';
    case Resolve = 'resolve';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
