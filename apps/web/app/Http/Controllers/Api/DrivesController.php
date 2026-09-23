<?php

namespace App\Http\Controllers\Api;

use App\Library\Drives\DriveNamespace;
use App\Models\Drive;
use App\Models\Variant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DrivesController
{
    /**
     * Drives a client can mount.
     */
    public function index(): JsonResponse
    {
        $drives = Drive::query()->where('is_active', true)->with('target')->orderBy('name')->get()
            ->map(fn (Drive $drive): array => [
                'slug' => $drive->slug,
                'name' => $drive->name,
                'root_path' => $drive->root_path,
                'path_layout' => $drive->path_layout,
                'target' => $drive->target?->slug,
                'manifest_url' => route('prismfs.drives.manifest', $drive),
            ])->values();

        return response()->json(['data' => $drives]);
    }

    /**
     * Where a variant's published files live on a drive.
     *
     * Paths are relative to the mount or UNC root; join them with the local
     * mount point. Defaults to the current version; stable drives also accept version.
     */
    public function variantPaths(Request $request, string $code, DriveNamespace $namespace): JsonResponse
    {
        $validated = $request->validate(['drive' => ['required', 'string', 'exists:drives,slug'], 'version' => ['nullable', 'integer', 'min:1']]);

        $variant = Variant::resolveCode($code);

        abort_if($variant === null || ! $variant->material->isVisibleTo($request->user()), 404);

        $drive = Drive::query()->where('slug', $validated['drive'])->firstOrFail();

        abort_unless($drive->is_active, 404);
        abort_if(isset($validated['version']) && $drive->path_layout !== 'stable', 422, 'Version selection requires a stable drive.');
        $projected = $namespace->entriesForVariant($drive, $variant, isset($validated['version']) ? (int) $validated['version'] : null);
        $entries = array_map(fn (array $entry): array => [
            'material_uuid' => $variant->material->uuid,
            'variant_uuid' => $variant->uuid,
            'material_version' => $entry['material_version'] ?? $variant->material->currentVersion?->number,
            'derivative_uuid' => $entry['derivative_uuid'] ?? null,
            'path' => $entry['path'],
            'target' => $entry['target'],
            'quality' => $entry['quality'],
            'role' => $entry['role'],
            'sha256' => $entry['sha256'],
            'bytes' => $entry['object']['size'],
            'mime_type' => $entry['mime_type'],
            'source_package_sha256' => $entry['source_package_sha256'],
            'converter' => $entry['converter'],
            'converter_version' => $entry['converter_version'],
        ], $projected);

        return response()->json([
            'data' => [
                'variant' => $variant->code,
                'variant_uuid' => $variant->uuid,
                'material_uuid' => $variant->material->uuid,
                'drive' => $drive->slug,
                'root_path' => $drive->root_path,
                'path_layout' => $drive->path_layout,
                'published' => $projected !== [],
                'files' => $entries,
            ],
        ]);
    }
}
