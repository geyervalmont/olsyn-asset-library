<?php

use App\Jobs\DownscaleRepresentation;
use App\Jobs\RenderPreview;

return [

    /*
    |--------------------------------------------------------------------------
    | Library file storage
    |--------------------------------------------------------------------------
    |
    | Library files are immutable and content-addressed: the object key is
    | derived from the file's SHA-256, so identical bytes are stored once.
    |
    */

    'files_disk' => env('OPAL_FILES_DISK', env('FILESYSTEM_DISK', 'local')),

    'files_prefix' => env('OPAL_FILES_PREFIX', 'opal/files'),

    /*
    |--------------------------------------------------------------------------
    | Namespace projection
    |--------------------------------------------------------------------------
    |
    | Bucket named in drive manifests when the files disk has none configured
    | (for example the local disk in tests).
    |
    */

    'bucket' => env('OPAL_BUCKET', env('AWS_BUCKET', 'prismfs-dev')),

    /*
    |--------------------------------------------------------------------------
    | Workers
    |--------------------------------------------------------------------------
    |
    | Tracked job types by registry key, so a run can be re-queued from its
    | record and the jobs page can name what ran.
    |
    */

    'workers' => [
        'downscale_representation' => DownscaleRepresentation::class,
        'render_preview' => RenderPreview::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Previews
    |--------------------------------------------------------------------------
    |
    | Render a swatch automatically when a canonical set is approved. Off in
    | tests so approvals stay cheap; the render job is exercised directly.
    |
    */

    'previews' => [
        'auto_render' => (bool) env('OPAL_AUTO_RENDER_PREVIEWS', true),
    ],

];
