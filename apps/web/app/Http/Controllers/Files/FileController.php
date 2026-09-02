<?php

namespace App\Http\Controllers\Files;

use App\Models\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a library file to a signed-in user. Files are immutable, so the
 * response may be cached for a long time.
 */
class FileController
{
    public function __invoke(File $file, ?string $name = null): StreamedResponse
    {
        abort_unless(auth()->user()?->can('materials.view'), 403);

        return Storage::disk($file->disk)->response($file->object_key, $name ?? $file->original_name, [
            'Content-Type' => $file->mime_type,
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }
}
