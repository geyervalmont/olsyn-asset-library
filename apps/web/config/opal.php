<?php

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

];
