<?php

namespace App\Http\Controllers\Files;

use App\Actions\Files\AuthorizeFileRead;
use App\Library\Previews\BrowserPreview;
use App\Models\File;
use App\Models\FileAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class PreviewController
{
    public function __invoke(Request $request, File $file, int $size, AuthorizeFileRead $authorize, BrowserPreview $previews): Response
    {
        $authorize->handle($file, $request->user());
        abort_unless(in_array($size, [96, 512, 1024], true), 404);
        abort_unless($file->isImage(), 415);

        $response = response('', 200, [
            'Content-Type' => 'image/webp',
            // Revalidate authorization even when the browser already has bytes.
            'Cache-Control' => 'private, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ])->setEtag($previews->key($file, $size));

        if ($response->isNotModified($request)) {
            return $response;
        }

        $bytes = $previews->contents($file, $size);
        FileAccess::create([
            'file_id' => $file->getKey(),
            'channel' => FileAccess::CHANNEL_WEB,
            'action' => 'preview',
            'user_id' => $request->user()?->id,
            'path' => $request->path(),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'bytes' => strlen($bytes),
            'accessed_at' => now(),
        ]);

        return $response->setContent($bytes);
    }
}
