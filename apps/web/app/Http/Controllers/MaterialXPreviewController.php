<?php

namespace App\Http\Controllers;

use App\Library\Previews\MaterialXPreview;
use App\Models\Material;
use App\Models\Package;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class MaterialXPreviewController extends Controller
{
    public function __invoke(Request $request, Package $package, MaterialXPreview $preview): Response
    {
        $user = $request->user();
        abort_unless($user?->can('materials.view'), 403);
        abort_unless(Material::query()->visibleTo($user)->whereKey($package->variant->material_id)->exists(), 404);

        $etag = hash('sha256', $preview->key($package));
        $response = response('', 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'private, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ])->setEtag($etag);
        if ($response->isNotModified($request)) {
            return $response;
        }
        try {
            return $response->setContent($preview->contents($package));
        } catch (Throwable $error) {
            report($error);

            return response()->json(['message' => 'The MaterialX preview is unavailable. You can still inspect the texture maps.'], 503)
                ->header('Cache-Control', 'no-store');
        }
    }
}
