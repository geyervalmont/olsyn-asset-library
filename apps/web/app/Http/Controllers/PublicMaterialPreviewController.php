<?php

namespace App\Http\Controllers;

use App\Library\Previews\MaterialPreviews;
use App\Models\FileAccess;
use App\Models\Material;
use App\Models\Variant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicMaterialPreviewController
{
    public function __invoke(Request $request, Material $material, Variant $variant, MaterialPreviews $previews): StreamedResponse
    {
        abort_unless((int) $variant->material_id === (int) $material->getKey(), 404);

        $file = $previews->variantFilesFor(collect([$variant]))[$variant->getKey()] ?? null;

        abort_if($file === null, 404);

        FileAccess::create([
            'file_id' => $file->getKey(),
            'channel' => FileAccess::CHANNEL_PUBLIC_QR,
            'action' => 'read',
            'principal' => 'public-qr',
            'path' => $request->path(),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'bytes' => $file->bytes,
            'accessed_at' => now(),
        ]);

        return Storage::disk($file->disk)->response($file->object_key, $file->original_name, [
            'Content-Type' => $file->mime_type,
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }
}
