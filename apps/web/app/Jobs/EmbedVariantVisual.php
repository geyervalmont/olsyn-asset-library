<?php

namespace App\Jobs;

use App\Library\Embeddings\EmbeddingInput;
use App\Library\Embeddings\EmbeddingProvider;
use App\Library\Embeddings\EmbeddingVector;
use App\Library\Embeddings\VariantVisualEmbeddingDocuments;
use App\Models\Embedding;
use App\Models\User;
use App\Models\Variant;
use App\Models\WorkerRun;
use DateTimeInterface;
use Illuminate\Queue\Middleware\RateLimited;

/** Index one variant from only its deterministic rendered appearance. */
class EmbedVariantVisual extends TrackedJob
{
    public int $maxExceptions = 5;

    public function retryUntil(): DateTimeInterface
    {
        // A first visual backfill can enqueue thousands of previews behind a
        // shared Bedrock rate limit; releases must not expire the tail.
        return now()->addHours(2);
    }

    /** @return list<RateLimited> */
    public function middleware(): array
    {
        return [(new RateLimited('material-embeddings'))->releaseAfter(10)];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public static function type(): string
    {
        return 'embed_variant_visual';
    }

    public static function forVariant(Variant $variant, ?User $actor = null, bool $force = false): WorkerRun
    {
        return static::launch(['variant_id' => $variant->getKey(), 'force' => $force], $variant, $actor, 'embeddings');
    }

    public static function forVariantHere(Variant $variant, ?User $actor = null, bool $force = false): WorkerRun
    {
        return static::launchHere(['variant_id' => $variant->getKey(), 'force' => $force], $variant, $actor);
    }

    public static function current(Variant $variant): ?Embedding
    {
        $provider = app(EmbeddingProvider::class);

        return Embedding::query()
            ->whereMorphedTo('embeddable', $variant)
            ->where('kind', VariantVisualEmbeddingDocuments::KIND)
            ->where('provider', $provider->name())
            ->where('model', $provider->model())
            ->first();
    }

    public static function isCurrent(Variant $variant): bool
    {
        $document = app(VariantVisualEmbeddingDocuments::class)->for($variant);
        $embedding = static::current($variant);

        return $document->image !== null
            && $embedding !== null
            && hash_equals($embedding->source_digest, $document->digest);
    }

    /** @return array<string, mixed> */
    protected function execute(WorkerRun $run): array
    {
        $variant = Variant::query()->findOrFail((int) ($run->payload['variant_id'] ?? 0));
        $provider = app(EmbeddingProvider::class);
        $document = app(VariantVisualEmbeddingDocuments::class)->for($variant);
        $existing = static::current($variant);

        if ($document->image === null) {
            $existing?->delete();

            return ['skipped' => 'variant has no rendered preview'];
        }

        if (! ($run->payload['force'] ?? false) && $existing !== null && hash_equals($existing->source_digest, $document->digest)) {
            return ['skipped' => 'visual embedding is current', 'embedding_id' => $existing->getKey()];
        }

        $values = $provider->embed(new EmbeddingInput(image: $document->image->contents()));
        $literal = EmbeddingVector::literal($values, $provider->dimensions());
        $embedding = Embedding::query()->updateOrCreate([
            'embeddable_type' => $variant->getMorphClass(),
            'embeddable_id' => $variant->getKey(),
            'kind' => VariantVisualEmbeddingDocuments::KIND,
            'provider' => $provider->name(),
            'model' => $provider->model(),
        ], [
            'dimensions' => $provider->dimensions(),
            'source_digest' => $document->digest,
            'source_text' => '',
            'image_file_id' => $document->image->getKey(),
            'embedding' => $literal,
            'metadata' => ['profile' => VariantVisualEmbeddingDocuments::PROFILE, 'worker_run' => $run->uuid],
        ]);

        return [
            'embedding_id' => $embedding->getKey(),
            'provider' => $provider->name(),
            'model' => $provider->model(),
            'dimensions' => $provider->dimensions(),
            'image_file_id' => $document->image->getKey(),
        ];
    }
}
