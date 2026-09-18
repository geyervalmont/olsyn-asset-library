<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

final class StudioLivePreviewController
{
    public function __invoke(Request $request, string $digest, string $role): Response
    {
        abort_unless($request->user()?->can('materials.contribute'), 403);
        $assets = Cache::get('studio-preview-v3:'.$request->user()->id.':'.$digest);
        abort_unless(is_array($assets) && isset($assets[$role]), 404);

        return response($assets[$role], 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=900',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
