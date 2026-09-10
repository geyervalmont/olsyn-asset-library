<?php

namespace App\Console\Commands;

use App\Jobs\EmbedMaterial;
use App\Models\Material;
use Illuminate\Console\Command;

class IndexMaterialEmbeddingsCommand extends Command
{
    protected $signature = 'opal:embeddings:index
        {--material= : Only this material, by code or alias}
        {--stale : Skip embeddings whose text and preview are unchanged}
        {--force : Call the provider even when the embedding is current}
        {--limit= : Process at most this many materials}
        {--sync : Run now instead of queueing}';

    protected $description = 'Generate multimodal vectors for material similarity search';

    public function handle(): int
    {
        if (! config('opal.embeddings.enabled')) {
            $this->components->error('Material embeddings are disabled. Set OPAL_EMBEDDINGS_ENABLED=true first.');

            return self::FAILURE;
        }

        $query = Material::query()->orderBy('id');

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
        $limit = $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : null;

        foreach ($query->lazy() as $material) {
            if ($limit !== null && $processed >= $limit) {
                break;
            }

            if ($this->option('stale') && ! $this->option('force') && EmbedMaterial::isCurrent($material)) {
                $skipped++;

                continue;
            }

            $run = $this->option('sync')
                ? EmbedMaterial::forMaterialHere($material, force: (bool) $this->option('force'))
                : EmbedMaterial::forMaterial($material, force: (bool) $this->option('force'));

            $processed++;
            $this->line(sprintf('  %s %s (%s)', $this->option('sync') ? 'indexed' : 'queued ', $material->code, substr($run->uuid, 0, 8)));
        }

        $this->components->twoColumnDetail($this->option('sync') ? 'Indexed' : 'Queued', (string) $processed);
        $this->components->twoColumnDetail('Current', (string) $skipped);

        return self::SUCCESS;
    }
}
