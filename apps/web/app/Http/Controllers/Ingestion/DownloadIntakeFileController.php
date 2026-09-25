<?php

namespace App\Http\Controllers\Ingestion;

use App\Models\DriveIntakeSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DownloadIntakeFileController
{
    public function __invoke(Request $request, string $batch, string $file): StreamedResponse
    {
        abort_unless($request->user()->can('materials.contribute'), 403);
        $session = DriveIntakeSession::query()->visibleTo($request->user())->where('uuid', $batch)->firstOrFail();
        $entry = $session->files()->where('uuid', $file)->whereNotNull('uploaded_at')->firstOrFail();

        return Storage::disk($entry->disk)->download($entry->object_key, basename($entry->path), [
            'Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'X-Checksum-Sha256' => $entry->sha256,
        ]);
    }
}
