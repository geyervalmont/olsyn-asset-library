<?php

namespace App\Library\Drives;

use App\Models\Drive;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Shared projections only; token authorization must happen before cache lookup. */
final class SharedDriveManifest
{
    // Bump when namespace construction changes without a data/schema change.
    private const CACHE_VERSION = 1;

    public function __construct(private DriveNamespace $namespace) {}

    /** @return array{body: string, etag: string} */
    public function get(Drive $drive): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return $this->build($drive);
        }
        $key = 'drive-manifest.v'.self::CACHE_VERSION.'.'.$drive->id;
        $fingerprint = $this->fingerprint($drive);
        $cached = Cache::get($key);
        if (is_array($cached) && ($cached['fingerprint'] ?? null) === $fingerprint) {
            return $cached['manifest'];
        }

        // Coalesce synchronized Nucleus/SMB refreshes instead of rebuilding twice.
        return Cache::lock($key.'.build', 120)->block(60, function () use ($drive, $key): array {
            $fingerprint = $this->fingerprint($drive);
            $cached = Cache::get($key);
            if (is_array($cached) && ($cached['fingerprint'] ?? null) === $fingerprint) {
                return $cached['manifest'];
            }
            $manifest = $this->build($drive);
            // A concurrent publication/revocation must never label an older
            // snapshot with the new revision. The following poll rebuilds it.
            if ($this->fingerprint($drive) === $fingerprint) {
                Cache::put($key, ['fingerprint' => $fingerprint, 'manifest' => $manifest], now()->addMinutes(30));
            }

            return $manifest;
        });
    }

    private function fingerprint(Drive $drive): string
    {
        $disks = [];
        foreach (config('filesystems.disks', []) as $name => $disk) {
            $disks[$name] = array_intersect_key($disk, array_flip(['driver', 'bucket', 'root', 'prefix']));
        }

        return hash('sha256', json_encode([
            DB::table('drive_namespace_revision')->where('id', 1)->value('revision'),
            $drive->getAttributes(), config('opal.packages_disk'), config('opal.bucket'), $disks,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{body: string, etag: string} */
    private function build(Drive $drive): array
    {
        $body = $this->namespace->toYaml($drive);

        return ['body' => $body, 'etag' => '"'.hash('sha256', $body).'"'];
    }
}
