<?php

namespace App\Http\Controllers\Api;

use App\Support\ClientReleaseCatalog;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class ClientReleasesController
{
    public function show(string $channel, ClientReleaseCatalog $releases): JsonResponse
    {
        try {
            $release = $releases->latest('revit', $channel);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json($release)->withHeaders([
            'Cache-Control' => 'public, max-age=60, stale-if-error=600',
        ]);
    }
}
