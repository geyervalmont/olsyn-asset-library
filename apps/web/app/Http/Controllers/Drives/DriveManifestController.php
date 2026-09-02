<?php

namespace App\Http\Controllers\Drives;

use App\Library\Drives\DriveNamespace;
use App\Models\Drive;
use Illuminate\Http\Response;

class DriveManifestController
{
    public function __invoke(Drive $drive, DriveNamespace $namespace): Response
    {
        abort_unless(auth()->user()?->can('materials.publish'), 403);

        return response($namespace->toYaml($drive), 200, [
            'Content-Type' => 'application/yaml; charset=utf-8',
            'Content-Disposition' => 'inline; filename="'.$drive->slug.'.namespace.yaml"',
        ]);
    }
}
