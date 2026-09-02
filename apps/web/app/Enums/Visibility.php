<?php

namespace App\Enums;

enum Visibility: string
{
    case Library = 'library';
    case Restricted = 'restricted';

    public function label(): string
    {
        return match ($this) {
            self::Library => 'Whole library',
            self::Restricted => 'Restricted to grantees',
        };
    }
}
