<?php

namespace App\Console\Commands;

use App\Jobs\BuildVariantPackage;
use App\Library\Packaging\PackageBuilder;
use App\Models\Variant;
use Illuminate\Console\Command;

/**
 * Queue package builds across the library.
 */
class PackageLibraryCommand extends Command
{
    protected $signature = 'opal:package
        {--only= : A single variant by code}
        {--limit= : Queue at most this many variants}
        {--force : Rebuild even when the inputs have not changed}
        {--sync : Build in this process instead of queueing}';

    protected $description = 'Build USDZ packages for the library';

    public function handle(PackageBuilder $builder): int
    {
        if (! $builder->available()) {
            $this->components->error('No materials toolbox is installed; set OPAL_TOOLBOX_BIN to the built binary.');

            return self::FAILURE;
        }

        $this->components->info(sprintf('Using %s %s.', $builder->name(), $builder->version()));

        $query = Variant::query()->orderBy('id');

        if ($only = $this->option('only')) {
            $query->where('code', $only);
        }

        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        $force = (bool) $this->option('force');
        $queued = 0;

        // Chunked so the whole library does not have to fit in memory to be
        // queued, and so a long run reports progress rather than going quiet.
        $query->chunkById(200, function ($variants) use ($force, &$queued): void {
            foreach ($variants as $variant) {
                if ($this->option('sync')) {
                    BuildVariantPackage::dispatchSync(BuildVariantPackage::forVariant($variant, $force)->getKey());
                } else {
                    BuildVariantPackage::forVariant($variant, $force);
                }

                $queued++;
            }

            $this->line("  queued {$queued}");
        });

        $this->components->info(sprintf('%d variant%s queued.', $queued, $queued === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
