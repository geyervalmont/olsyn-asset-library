<?php

namespace App\Http\Controllers;

use App\Library\Studio\DraftStore;
use App\Models\StudioDraft;
use App\Models\StudioRevision;
use Illuminate\Http\Response;

final class StudioArtifactController
{
    public function __invoke(StudioRevision $revision, string $role, DraftStore $store): Response
    {
        abort_unless(auth()->user()?->can('materials.contribute'), 403);
        abort_unless(StudioDraft::query()->owned()->whereKey($revision->studio_draft_id)->exists(), 404);
        $asset = $role === 'source' ? ($revision->document['source'] ?? null) : ($revision->artifacts[$role] ?? null);
        abort_unless(is_array($asset), 404);

        return response($store->bytes($asset), 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
