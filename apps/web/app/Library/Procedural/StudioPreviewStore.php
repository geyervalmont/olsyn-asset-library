<?php

namespace App\Library\Procedural;

use App\Jobs\PurgeStudioPreview;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Temporary, private material maps used for a direct Studio → Revit apply. */
final class StudioPreviewStore
{
    /**
     * @return array{id: string, label: string, tile_width_mm: float, expires_at: string, maps: list<array<string, mixed>>}
     */
    public function put(User $user, ProceduralBake $bake, string $label): array
    {
        $id = (string) Str::uuid();
        $disk = (string) config('opal.studio_previews.disk');
        $prefix = trim((string) config('opal.studio_previews.prefix'), '/').'/'.$id;
        $expires = now()->addMinutes((int) config('opal.studio_previews.ttl_minutes'));
        $assets = [];

        foreach ($bake->assets as $asset) {
            if (! str_starts_with($asset->mimeType, 'image/')) {
                continue;
            }

            $path = $prefix.'/'.$asset->role.'.'.$asset->extension;
            Storage::disk($disk)->put($path, $asset->contents);
            $assets[$asset->role] = [
                'role' => $asset->role,
                'path' => $path,
                'extension' => $asset->extension,
                'media_type' => $asset->mimeType,
                'sha256' => $asset->sha256,
                'bytes' => strlen($asset->contents),
            ];

            // Revit's Generic appearance asset consumes glossiness, which is
            // the inverse of the canonical roughness channel. Include the
            // correctly transformed map in this transient Revit payload.
            if ($asset->role === 'roughness') {
                $contents = $this->invertRoughness($asset->contents);
                $path = $prefix.'/glossiness.png';
                Storage::disk($disk)->put($path, $contents);
                $assets['glossiness'] = [
                    'role' => 'glossiness',
                    'path' => $path,
                    'extension' => 'png',
                    'media_type' => 'image/png',
                    'sha256' => hash('sha256', $contents),
                    'bytes' => strlen($contents),
                ];
            }
        }

        Cache::put($this->key($id), [
            'user_id' => $user->getKey(),
            'assets' => $assets,
        ], $expires);
        PurgeStudioPreview::dispatch($id)->delay($expires);

        $maps = [];
        foreach ($assets as $asset) {
            $maps[] = [
                'role' => $asset['role'],
                'extension' => $asset['extension'],
                'media_type' => $asset['media_type'],
                'sha256' => $asset['sha256'],
                'bytes' => $asset['bytes'],
                'url' => route('api.studio-previews.show', ['preview' => $id, 'role' => $asset['role']]),
            ];
        }

        return [
            'id' => $id,
            'label' => $label,
            'tile_width_mm' => $bake->widthMm,
            'expires_at' => $expires->toIso8601String(),
            'maps' => $maps,
        ];
    }

    /** @return array<string, mixed>|null */
    public function asset(string $id, string $role, User $user): ?array
    {
        $preview = Cache::get($this->key($id));

        if (! is_array($preview) || (int) ($preview['user_id'] ?? 0) !== (int) $user->getKey()) {
            return null;
        }

        $asset = $preview['assets'][$role] ?? null;

        return is_array($asset) ? $asset : null;
    }

    public function purge(string $id): void
    {
        Cache::forget($this->key($id));
        $prefix = trim((string) config('opal.studio_previews.prefix'), '/').'/'.$id;
        Storage::disk((string) config('opal.studio_previews.disk'))->deleteDirectory($prefix);
    }

    private function key(string $id): string
    {
        return 'opal:studio-preview:'.$id;
    }

    private function invertRoughness(string $contents): string
    {
        $image = @imagecreatefromstring($contents);
        throw_if($image === false, \RuntimeException::class, 'The generated roughness map is not a readable image.');
        imagefilter($image, IMG_FILTER_NEGATE);
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
