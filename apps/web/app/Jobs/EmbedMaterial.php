<?php

namespace App\Jobs;

use App\Library\Embeddings\EmbeddingInput;
use App\Library\Embeddings\EmbeddingProvider;
use App\Library\Embeddings\EmbeddingVector;
use App\Library\Embeddings\MaterialEmbeddingDocuments;
use App\Models\Embedding;
use App\Models\Material;
use App\Models\User;
use App\Models\WorkerRun;
use DateTimeInterface;
use Illuminate\Queue\Middleware\RateLimited;

class EmbedMaterial extends TrackedJob
{
    public int $maxExceptions = 5;

    /**
     * Rate-limit releases count as attempts. A time window lets a large
     * backfill wait its turn without exhausting attempts before it can run.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }

    /**
     * @return list<RateLimited>
     */
    public function middleware(): array
    {
        return [(new RateLimited('material-embeddings'))->releaseAfter(10)];
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public static function type(): string
    {
        return 'embed_material';
    }

    public static function forMaterial(Material $material, ?User $actor = null, bool $force = false): WorkerRun
    {
        return static::launch(['material_id' => $material->getKey(), 'force' => $force], $material, $actor, 'embeddings');
    }

    public static function forMaterialHere(Material $material, ?User $actor = null, bool $force = false): WorkerRun
    {
        return static::launchHere(['material_id' => $material->getKey(), 'force' => $force], $material, $actor);
    }

    public static function current(Material $material): ?Embedding
    {
        $provider = app(EmbeddingProvider::class);

        return Embedding::query()
            ->whereMorphedTo('embeddable', $material)
            ->where('kind', MaterialEmbeddingDocuments::KIND)
            ->where('provider', $provider->name())
            ->where('model', $provider->model())
            ->first();
    }

    public static function isCurrent(Material $material): bool
    {
        $embedding = static::current($material);

        return $embedding !== null
            && hash_equals($embedding->source_digest, app(MaterialEmbeddingDocuments::class)->for($material)->digest);
    }

    /**
     * @return array<string, mixed>
     */
    protected function execute(WorkerRun $run): array
    {
        $material = Material::query()->findOrFail((int) ($run->payload['material_id'] ?? 0));
        $provider = app(EmbeddingProvider::class);
        $document = app(MaterialEmbeddingDocuments::class)->for($material);
        $existing = static::current($material);

        if (! ($run->payload['force'] ?? false) && $existing !== null && hash_equals($existing->source_digest, $document->digest)) {
            return ['skipped' => 'embedding is current', 'embedding_id' => $existing->getKey()];
        }

        $values = $provider->embed(new EmbeddingInput($document->text, $document->image?->contents()));
        $literal = EmbeddingVector::literal($values, $provider->dimensions());

        $embedding = Embedding::query()->updateOrCreate([
            'embeddable_type' => $material->getMorphClass(),
            'embeddable_id' => $material->getKey(),
            'kind' => MaterialEmbeddingDocuments::KIND,
            'provider' => $provider->name(),
            'model' => $provider->model(),
        ], [
            'dimensions' => $provider->dimensions(),
            'source_digest' => $document->digest,
            'source_text' => $document->text,
            'image_file_id' => $document->image?->getKey(),
            'embedding' => $literal,
            'metadata' => ['profile' => MaterialEmbeddingDocuments::PROFILE, 'worker_run' => $run->uuid],
        ]);

        return [
            'embedding_id' => $embedding->getKey(),
            'provider' => $provider->name(),
            'model' => $provider->model(),
            'dimensions' => $provider->dimensions(),
            'image_file_id' => $document->image?->getKey(),
        ];
    }
}
