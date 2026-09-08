<?php

namespace App\Actions\Packaging;

use App\Library\Packaging\PackageBuilder;
use App\Models\Package;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Build one variant's USDZ and record it.
 *
 * Re-runnable by design: a variant whose inputs have not changed is skipped
 * rather than rebuilt, so the pipeline can be pointed at the whole library as
 * often as needed and costs only what actually moved.
 */
class PackageVariant
{
    public function __construct(
        private readonly AssembleBuildRequest $assemble,
        private readonly PackageBuilder $builder,
    ) {}

    /**
     * Returns the package, or null when the variant has nothing to package.
     */
    public function handle(Variant $variant, bool $force = false): ?Package
    {
        $request = $this->assemble->handle($variant);

        if ($request->isEmpty()) {
            return null;
        }

        $digest = $request->digest();

        if (! $force) {
            $existing = Package::query()
                ->where('variant_id', $variant->getKey())
                ->where('request_digest', $digest)
                ->orderByDesc('revision')
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        if (! $this->builder->available()) {
            throw new RuntimeException(
                'No USD toolbox is available, so ['.$variant->code.'] cannot be packaged.'
            );
        }

        $built = $this->builder->build($request);

        // A forced rebuild of unchanged inputs produces byte-identical output,
        // because packaging is deterministic. That is a result worth reporting
        // rather than a revision worth creating: the archive gains nothing from
        // a second copy of the same bytes under a new number.
        $identical = Package::query()->where('sha256', $built->sha256)->first();

        if ($identical !== null) {
            @unlink($built->path);

            return $identical;
        }

        // The revision is claimed inside the transaction that writes the row,
        // so two workers packaging the same variant cannot agree on a number.
        // The lock is taken on the variant rather than on its packages, because
        // Postgres will not lock the rows behind an aggregate — and locking the
        // parent is what actually serialises builds of the same variant.
        return DB::transaction(function () use ($variant, $built, $digest): Package {
            Variant::query()->whereKey($variant->getKey())->lockForUpdate()->first();

            $revision = (int) Package::query()
                ->where('variant_id', $variant->getKey())
                ->max('revision') + 1;

            $key = $this->store($variant, $revision, $built->path);

            return $variant->packages()->create([
                'revision' => $revision,
                'object_key' => $key,
                'sha256' => $built->sha256,
                'request_digest' => $digest,
                'bytes' => $built->bytes,
                'tiers' => $built->tiers,
                'builder' => $built->builder,
                'builder_version' => $built->builderVersion,
                'losses' => $built->losses,
                'built_at' => now(),
            ]);
        });
    }

    /**
     * Readable rather than content-addressed, because the point of the archive
     * is that a person can navigate it: material, variant, revision. Rows are
     * immutable and revisions only increase, so a key is never reused.
     */
    private function store(Variant $variant, int $revision, string $path): string
    {
        $key = sprintf(
            '%s/%s/r%d.usdz',
            trim((string) config('opal.packages_prefix'), '/'),
            $variant->code,
            $revision,
        );

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("The built package for [{$variant->code}] could not be read back from [{$path}].");
        }

        try {
            Storage::disk((string) config('opal.packages_disk'))->put($key, $handle);
        } finally {
            fclose($handle);
            @unlink($path);
        }

        return $key;
    }
}
