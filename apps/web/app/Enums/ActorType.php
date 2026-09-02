<?php

namespace App\Enums;

enum ActorType: string
{
    case User = 'user';
    case Worker = 'worker';
    case External = 'external';
}
