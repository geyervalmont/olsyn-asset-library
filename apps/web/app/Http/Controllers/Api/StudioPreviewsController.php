<?php

namespace App\Http\Controllers\Api;

use App\Library\Procedural\StudioPreviewStore;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class StudioPreviewsController
{
    public function show(Request $request, StudioPreviewStore $store, string $preview, string $role): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $asset = $store->asset($preview, $role, $user);
        abort_if($asset === null, 404);

        $disk = Storage::disk((string) config('opal.studio_previews.disk'));
        abort_unless($disk->exists($asset['path']), 404);

        return response()->stream(function () use ($disk, $asset): void {
            $stream = $disk->readStream($asset['path']);
            throw_unless(is_resource($stream), \RuntimeException::class, 'The Studio preview could not be read.');
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $asset['media_type'],
            'Content-Length' => (string) $asset['bytes'],
            'Cache-Control' => 'private, max-age=900',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
