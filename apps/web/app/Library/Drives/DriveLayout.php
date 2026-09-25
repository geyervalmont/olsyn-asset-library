<?php

namespace App\Library\Drives;

/** The logical drive layout. Transports choose a mount point, not folder names. */
final class DriveLayout
{
    public const LABEL = 'OPAL';

    public const MATERIALS = '/materials';

    public const UPLOAD = '/upload';

    /** @return array{label: string, revision: int, materials: string, upload: string} */
    public static function describe(): array
    {
        return ['label' => self::LABEL, 'revision' => 1, 'materials' => self::MATERIALS, 'upload' => self::UPLOAD];
    }
}
