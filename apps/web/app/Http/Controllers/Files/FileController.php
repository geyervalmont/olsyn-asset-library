<?php

namespace App\Http\Controllers\Files;

use App\Actions\Files\AuthorizeFileRead;
use App\Models\File;
use App\Models\FileAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a file only through a material visible to the signed-in user.
 */
class FileController
{
    public function __invoke(Request $request, File $file, ?string $name = null): StreamedResponse
    {
        app(AuthorizeFileRead::class)->handle($file, $request->user());

        FileAccess::create([
            'file_id' => $file->getKey(),
            'channel' => FileAccess::CHANNEL_WEB,
            'action' => 'read',
            'user_id' => auth()->id(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'bytes' => $file->bytes,
            'accessed_at' => now(),
        ]);

        return Storage::disk($file->disk)->response($file->object_key, $name ?? $file->original_name, [
            'Content-Type' => $file->mime_type,
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
