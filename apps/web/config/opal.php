<?php

use App\Jobs\BuildVariantPackage;
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
    | Packaging
    |--------------------------------------------------------------------------
    |
    | Built USDZ packages: the archive the library is managed as. Keys are
    | readable rather than content-addressed, because navigating it by hand is
    | the point; immutability comes from revisions never being reused.
    |
    | The toolbox is invoked as a subprocess. Without it the pipeline still
    | runs and reports plainly that nothing can be built, rather than appearing
    | to work.
    |
    */

    'packages_disk' => env('OPAL_PACKAGES_DISK', env('OPAL_FILES_DISK', env('FILESYSTEM_DISK', 'local'))),

    'packages_prefix' => env('OPAL_PACKAGES_PREFIX', 'opal/packages'),

    'toolbox_bin' => env('OPAL_TOOLBOX_BIN'),

    'toolbox_timeout' => (int) env('OPAL_TOOLBOX_TIMEOUT', 900),

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
        'build_variant_package' => BuildVariantPackage::class,
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

    /*
    |--------------------------------------------------------------------------
    | Realtime
    |--------------------------------------------------------------------------
    |
    | What LAN clients (the Revit extension) connect to for live commands:
    | the Reverb server as reachable from their machine, not from this app.
    | Browsers use the VITE_REVERB_* values instead.
    |
    */

    'realtime' => [
        'scheme' => env('OPAL_REALTIME_SCHEME', 'http'),
        'host' => env('OPAL_REALTIME_HOST', env('REVERB_HOST', '127.0.0.1')),
        'port' => (int) env('OPAL_REALTIME_PORT', env('REVERB_PORT', 8080)),
        'key' => env('REVERB_APP_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Client sessions
    |--------------------------------------------------------------------------
    |
    | A session is live while it heartbeats within this many seconds.
    | Device links (the code a client shows to be linked) expire after
    | link_ttl minutes.
    |
    */

    'sessions' => [
        'live_seconds' => (int) env('OPAL_SESSION_LIVE_SECONDS', 90),
        'link_ttl' => (int) env('OPAL_LINK_TTL_MINUTES', 10),
    ],

];
