<?php

namespace App\Console\Commands;

use App\Jobs\EmbedMaterial;
use App\Jobs\EmbedVariantVisual;
use App\Library\Embeddings\VariantVisualEmbeddingDocuments;
use App\Models\Material;
use Illuminate\Console\Command;

class IndexMaterialEmbeddingsCommand extends Command
{
    protected $signature = 'opal:embeddings:index
        {--material= : Only this material, by code or alias}
        {--scope=all : semantic, visual, or all}
        {--stale : Skip embeddings whose text and preview are unchanged}
        {--force : Call the provider even when the embedding is current}
        {--limit= : Process at most this many embedding records}
        {--sync : Run now instead of queueing}';

    protected $description = 'Generate text-only material and image-only variant similarity vectors';

    public function handle(): int
    {
        if (! config('opal.embeddings.enabled')) {
            $this->components->error('Material embeddings are disabled. Set OPAL_EMBEDDINGS_ENABLED=true first.');

            return self::FAILURE;
        }

        $query = Material::query()->orderBy('id');
        $scope = strtolower((string) $this->option('scope'));

        if (! in_array($scope, ['semantic', 'visual', 'all'], true)) {
            $this->components->error('Scope must be semantic, visual, or all.');

            return self::FAILURE;
        }

        if ($this->option('material') !== null) {
            $material = Material::resolveCode((string) $this->option('material'));

            if ($material === null) {
                $this->components->error(sprintf('No material matches [%s].', $this->option('material')));

                return self::FAILURE;
            }

            $query->whereKey($material->getKey());
        }

        $processed = 0;
        $skipped = 0;
        $unavailable = 0;
        $limit = $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : null;

        foreach ($query->lazy() as $material) {
            if (in_array($scope, ['semantic', 'all'], true) && ($limit === null || $processed < $limit)) {
                if ($this->option('stale') && ! $this->option('force') && EmbedMaterial::isCurrent($material)) {
                    $skipped++;
                } else {
                    $run = $this->option('sync')
                        ? EmbedMaterial::forMaterialHere($material, force: (bool) $this->option('force'))
                        : EmbedMaterial::forMaterial($material, force: (bool) $this->option('force'));

                    $processed++;
                    $this->line(sprintf('  %s semantic %s (%s)', $this->option('sync') ? 'indexed' : 'queued ', $material->code, substr($run->uuid, 0, 8)));
                }
            }

            if (! in_array($scope, ['visual', 'all'], true)) {
                continue;
            }

            foreach ($material->variants()->orderBy('id')->cursor() as $variant) {
                if ($limit !== null && $processed >= $limit) {
                    break 2;
                }

                if (app(VariantVisualEmbeddingDocuments::class)->for($variant)->image === null) {
                    $unavailable++;

                    continue;
                }

                if ($this->option('stale') && ! $this->option('force') && EmbedVariantVisual::isCurrent($variant)) {
                    $skipped++;

                    continue;
                }

                $run = $this->option('sync')
                    ? EmbedVariantVisual::forVariantHere($variant, force: (bool) $this->option('force'))
                    : EmbedVariantVisual::forVariant($variant, force: (bool) $this->option('force'));

                $processed++;
                $this->line(sprintf('  %s visual   %s (%s)', $this->option('sync') ? 'indexed' : 'queued ', $variant->code, substr($run->uuid, 0, 8)));
            }
        }

        $this->components->twoColumnDetail($this->option('sync') ? 'Indexed' : 'Queued', (string) $processed);
        $this->components->twoColumnDetail('Current', (string) $skipped);
        $this->components->twoColumnDetail('No visual source', (string) $unavailable);

        return self::SUCCESS;
    }
}
