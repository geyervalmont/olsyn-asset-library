<?php

namespace App\Library\Drives;

use App\Models\DriveIntakeSession;
use Illuminate\Http\Request;

/** The logical drive layout. Transports choose a mount point, not folder names. */
final class DriveLayout
{
    public const LABEL = 'OPAL';

    public const MATERIALS = '/materials';

    public const INGESTION = '/ingestion';

    public const UPLOAD = '/ingestion/upload';

    public const WORKSPACE = '/ingestion/workspace';

    // Installed clients through 0.1.5 validate the old path literally. Their
    // projection remains an alias of the same inbox, never a second upload queue.
    public static function revision(Request $request): int
    {
        return $request->header('X-Opal-Drive-Layout') === '2' ? 2 : 1;
    }

    public static function intakePath(DriveIntakeSession $session, int $revision): string
    {
        return $session->is_inbox && $revision === 1 ? '/upload' : $session->drivePath();
    }

    /** @return array<string, string|int> */
    public static function describe(int $revision = 2): array
    {
        if ($revision === 1) {
            return ['label' => self::LABEL, 'revision' => 1, 'materials' => self::MATERIALS, 'upload' => '/upload'];
        }

        return ['label' => self::LABEL, 'revision' => 2, 'materials' => self::MATERIALS,
            'ingestion' => self::INGESTION, 'upload' => self::UPLOAD, 'workspace' => self::WORKSPACE];
    }
}
