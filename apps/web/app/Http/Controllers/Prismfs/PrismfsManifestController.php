<?php

namespace App\Http\Controllers\Prismfs;

use App\Library\Drives\DriveNamespace;
use App\Models\Drive;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The manifest PrismFS polls. Authenticated by the drive's bearer token,
 * with an ETag so an unchanged namespace costs a 304.
 */
class PrismfsManifestController
{
    public function __invoke(Request $request, Drive $drive, DriveNamespace $namespace): Response
    {
        if (! $drive->is_active || ! $drive->tokenMatches($request->bearerToken())) {
            return response('Unauthorized', 401, ['WWW-Authenticate' => 'Bearer realm="opal-drive"']);
        }

        $yaml = $namespace->toYaml($drive);
        $etag = '"'.hash('sha256', $yaml).'"';

        if (in_array($etag, array_map('trim', explode(',', (string) $request->header('If-None-Match'))), true)) {
            return response('', 304, ['ETag' => $etag, 'Cache-Control' => 'no-cache']);
        }

        return response($yaml, 200, [
            'Content-Type' => 'application/yaml; charset=utf-8',
            'ETag' => $etag,
            'Cache-Control' => 'no-cache',
        ]);
    }
}
