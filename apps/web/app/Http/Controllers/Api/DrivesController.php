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
                'target' => $drive->target?->slug,
                'manifest_url' => route('prismfs.drives.manifest', $drive),
            ])->values();

        return response()->json(['data' => $drives]);
    }

    /**
     * Where a variant's published files live on a drive.
     *
     * Paths are relative to the mount or UNC root; join them with the local
     * mount point. Only the current version's files appear.
     */
    public function variantPaths(Request $request, string $code, DriveNamespace $namespace): JsonResponse
    {
        $validated = $request->validate(['drive' => ['required', 'string', 'exists:drives,slug']]);

        $variant = Variant::resolveCode($code);

        abort_if($variant === null || ! $variant->material->isVisibleTo($request->user()), 404);

        $drive = Drive::query()->where('slug', $validated['drive'])->firstOrFail();

        $entries = array_map(fn (array $entry): array => [
            'path' => $entry['path'],
            'target' => $entry['target'],
            'quality' => $entry['quality'],
            'role' => $entry['role'],
            'sha256' => $entry['sha256'],
            'bytes' => $entry['object']['size'],
            'mime_type' => $entry['mime_type'],
        ], $namespace->entriesForVariant($drive, $variant));

        return response()->json([
            'data' => [
                'variant' => $variant->code,
                'drive' => $drive->slug,
                'root_path' => $drive->root_path,
                'published' => $variant->material->current_version_id !== null,
                'files' => $entries,
            ],
        ]);
    }
}
