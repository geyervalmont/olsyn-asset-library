<?php

namespace App\Console\Commands;

use App\Jobs\DownscaleRepresentation;
use App\Models\QualityTier;
use App\Models\Representation;
use App\Models\Target;
use Illuminate\Console\Command;

class BackfillTiersCommand extends Command
{
    protected $signature = 'opal:tiers:backfill
        {--target=pbr : Target whose approved representations get lower tiers}
        {--tiers=2k,1k,preview : Tiers to produce, when smaller than the source}
        {--limit= : Queue at most this many jobs}';

    protected $description = 'Queue downscale jobs for approved representations missing lower quality tiers';

    public function handle(): int
    {
        $target = Target::findBySlug((string) $this->option('target'));

        if ($target === null) {
            $this->components->error(sprintf('Unknown target [%s].', $this->option('target')));

            return self::FAILURE;
        }

        $tiers = collect(explode(',', (string) $this->option('tiers')))
            ->map(fn (string $slug): ?QualityTier => QualityTier::findBySlug(trim($slug)))
            ->filter()
            ->values();

        $queued = 0;
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        Representation::query()
            ->approved()
            ->where('target_id', $target->getKey())
            ->with(['quality', 'variant'])
            ->orderBy('id')
            ->each(function (Representation $representation) use ($tiers, &$queued, $limit): bool {
                $sourcePixels = $representation->quality->pixels ?? 0;

                $missing = $tiers
                    ->filter(fn (QualityTier $tier): bool => $tier->pixels !== null && $tier->pixels < $sourcePixels)
                    ->reject(fn (QualityTier $tier): bool => Representation::query()->forKey($representation->variant, $representation->target, $tier)->approved()->exists())
                    ->map(fn (QualityTier $tier): string => $tier->slug)
                    ->values()
                    ->all();
                $missing = array_values($missing);

                if ($missing === []) {
                    return true;
                }

                DownscaleRepresentation::forRepresentation($representation, $missing);
                $queued++;

                return $limit === null || $queued < $limit;
            });

        $this->components->info(sprintf('Queued %d downscale job(s).', $queued));

        return self::SUCCESS;
    }
}
