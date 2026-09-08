<?php

namespace App\Console\Commands;

use App\Jobs\BuildVariantPackage;
use App\Library\Packaging\PackageBuilder;
use App\Models\Variant;
use Illuminate\Console\Command;
use Throwable;

/**
 * Queue package builds across the library.
 */
class PackageLibraryCommand extends Command
{
    protected $signature = 'opal:package
        {--only= : A single variant by code}
        {--limit= : Queue at most this many variants}
        {--force : Rebuild even when the inputs have not changed}
        {--sync : Build in this process instead of queueing}
        {--stop-on-error : Halt on the first failure instead of recording and continuing}';

    protected $description = 'Build USDZ packages for the library';

    public function handle(PackageBuilder $builder): int
    {
        if (! $builder->available()) {
            $this->components->error('No USD toolbox is installed; set OPAL_TOOLBOX_BIN to the built binary.');

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
        $sync = (bool) $this->option('sync');
        $stopOnError = (bool) $this->option('stop-on-error');

        $done = 0;
        $failed = [];

        // Chunked so the whole library does not have to fit in memory to be
        // queued, and so a long run reports progress rather than going quiet.
        $query->chunkById(200, function ($variants) use ($force, $sync, $stopOnError, &$done, &$failed): bool {
            foreach ($variants as $variant) {
                try {
                    if ($sync) {
                        BuildVariantPackage::dispatchSync(BuildVariantPackage::forVariant($variant, $force)->getKey());
                    } else {
                        BuildVariantPackage::forVariant($variant, $force);
                    }
                } catch (Throwable $failure) {
                    // One variant must not end a run of thousands. The corpus
                    // contains normal maps whose convention was never recorded,
                    // and packaging refuses those by design — correctly, but
                    // that is a reason to skip one material, not to stop.
                    $failed[$variant->code] = $this->reason($failure);

                    if ($stopOnError) {
                        return false;
                    }

                    continue;
                }

                $done++;
            }

            $this->line(sprintf('  %d packaged, %d failed', $done, count($failed)));

            return true;
        });

        $this->components->info(sprintf('%d variant%s %s.', $done, $done === 1 ? '' : 's', $sync ? 'packaged' : 'queued'));

        if ($failed !== []) {
            $this->newLine();
            $this->components->warn(sprintf('%d could not be packaged:', count($failed)));

            foreach (array_count_values($failed) as $reason => $count) {
                $this->line(sprintf('  %5d  %s', $count, $reason));
            }

            $this->newLine();
            $this->line('  Examples: '.implode(', ', array_slice(array_keys($failed), 0, 3)));
        }

        // A run that skipped some variants still did its job. Failing the whole
        // command would put a Kubernetes Job into backoff over a handful of
        // materials the corpus never described properly.
        return self::SUCCESS;
    }

    /**
     * Collapse an exception into something countable, so a summary reports
     * "1,204 missing a normal convention" rather than 1,204 near-identical
     * sentences.
     */
    private function reason(Throwable $failure): string
    {
        $message = $failure->getMessage();

        return match (true) {
            str_contains($message, 'normal_convention') => 'normal convention not recorded',
            str_contains($message, 'scene description') => 'scene description over the size ceiling',
            str_contains($message, 'invalid build manifest') => 'manifest rejected by the toolbox',
            str_contains($message, 'no canonical files') => 'no canonical files',
            str_contains($message, 'duplicate key') => 'identical to an existing package',
            default => trim(explode(PHP_EOL, $message)[0]),
        };
    }
}
