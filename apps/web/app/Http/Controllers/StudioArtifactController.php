<?php

namespace App\Http\Controllers;

use App\Library\Studio\DraftStore;
use App\Models\StudioDraft;
use App\Models\StudioRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class StudioArtifactController
{
    public function __invoke(StudioRevision $revision, string $role, DraftStore $store, Request $request): Response
    {
        abort_unless(auth()->user()?->can('materials.contribute'), 403);
        abort_unless(StudioDraft::query()->owned()->whereKey($revision->studio_draft_id)->exists(), 404);
        $asset = $role === 'source' ? ($revision->document['source'] ?? null) : ($revision->artifacts[$role] ?? $revision->run?->artifacts[$role] ?? null);
        abort_unless(is_array($asset), 404);

        $bytes = $store->bytes($asset);
        if ($request->boolean('thumbnail')) {
            $image = new \Imagick;
            $image->readImageBlob($bytes);
            $image->thumbnailImage(160, 160, true);
            $image->stripImage();
            $bytes = $image->getImageBlob();
            $image->clear();
        }

        return response($bytes, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
