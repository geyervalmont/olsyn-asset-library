<?php

namespace App\Http\Controllers\Api;

use App\Support\ClientReleaseCatalog;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class ClientReleasesController
{
    public function show(ClientReleaseCatalog $releases): JsonResponse
    {
        return $this->response($releases);
    }

    public function showVersion(int $revitVersion, ClientReleaseCatalog $releases): JsonResponse
    {
        return $this->response($releases, $revitVersion);
    }

    public function legacyChannel(string $channel, ClientReleaseCatalog $releases): JsonResponse
    {
        return $this->response($releases);
    }

    public function legacyVersionChannel(int $revitVersion, string $channel, ClientReleaseCatalog $releases): JsonResponse
    {
        return $this->response($releases, $revitVersion);
    }

    private function response(ClientReleaseCatalog $releases, ?int $revitVersion = null): JsonResponse
    {
        try {
            $release = $releases->latest('revit', $revitVersion);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json($release)->withHeaders([
            'Cache-Control' => 'public, max-age=60, stale-if-error=600',
        ]);
    }
}
