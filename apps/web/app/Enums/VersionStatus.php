<?php

namespace App\Enums;

enum VersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Superseded = 'superseded';
}
