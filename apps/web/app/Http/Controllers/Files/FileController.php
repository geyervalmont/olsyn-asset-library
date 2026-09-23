<?php

namespace App\Http\Controllers\Files;

use App\Models\File;
use App\Models\FileAccess;
use App\Models\Material;
use App\Models\PackageDerivativeFile;
use App\Models\RepresentationFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a file only through a material visible to the signed-in user.
 */
class FileController
{
    public function __invoke(Request $request, File $file, ?string $name = null): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user?->can('materials.view'), 403);
        // The drive API is not the only way to address bytes: enforce the same
        // material grants on legacy browser URLs as well. Unattached files are
        // only available to publishers, who already have library-wide access.
        $visible = Material::query()->visibleTo($user)->select('id');
        abort_unless($user->can('materials.publish')
            || RepresentationFile::query()->where('file_id', $file->id)
                ->whereHas('representation.variant', fn ($query) => $query->whereIn('material_id', clone $visible))->exists()
            || PackageDerivativeFile::query()->where('file_id', $file->id)
                ->whereHas('derivative.package.variant', fn ($query) => $query->whereIn('material_id', clone $visible))->exists(), 404);

        FileAccess::create([
            'file_id' => $file->getKey(),
            'channel' => FileAccess::CHANNEL_WEB,
            'action' => 'read',
            'user_id' => auth()->id(),
            'path' => $request->path(),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
            'bytes' => $file->bytes,
            'accessed_at' => now(),
        ]);

        return Storage::disk($file->disk)->response($file->object_key, $name ?? $file->original_name, [
            'Content-Type' => $file->mime_type,
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
