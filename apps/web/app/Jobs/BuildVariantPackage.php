<?php

namespace App\Jobs;

use App\Actions\Packaging\PackageVariant;
use App\Models\User;
use App\Models\Variant;
use App\Models\WorkerRun;

/**
 * Build one variant's USDZ.
 *
 * One variant per job rather than one job for the library: a package is
 * independent of every other, so a failure costs one variant, and the queue
 * gives back parallelism and retries without any of it being written here.
 */
class BuildVariantPackage extends TrackedJob
{
    public int $timeout = 1200;

    public static function type(): string
    {
        return 'build_variant_package';
    }

    public static function forVariant(Variant $variant, bool $force = false, ?User $actor = null): WorkerRun
    {
        return static::launch(['variant_id' => $variant->getKey(), 'force' => $force], $variant, $actor);
    }

    /**
     * Package this variant in the calling process, still recording the run.
     */
    public static function forVariantHere(Variant $variant, bool $force = false, ?User $actor = null): WorkerRun
    {
        return static::launchHere(['variant_id' => $variant->getKey(), 'force' => $force], $variant, $actor);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function execute(WorkerRun $run): ?array
    {
        $variant = Variant::query()->findOrFail((int) ($run->payload['variant_id'] ?? 0));

        $package = app(PackageVariant::class)->handle($variant, (bool) ($run->payload['force'] ?? false));

        if ($package === null) {
            return ['packaged' => false, 'reason' => 'no canonical files to package'];
        }

        return [
            'packaged' => true,
            'revision' => $package->revision,
            'object_key' => $package->object_key,
            'bytes' => $package->bytes,
            'tiers' => $package->tiers,
            // Surfaced in the run record rather than only in the row, because a
            // conversion that dropped something is worth seeing on the jobs
            // page without going looking for it.
            'losses' => count($package->losses ?? []),
        ];
    }
}
