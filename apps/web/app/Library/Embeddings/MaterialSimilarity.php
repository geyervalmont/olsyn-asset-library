<?php

namespace App\Library\Embeddings;

use App\Models\Embedding;
use App\Models\Material;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Cache;

final class MaterialSimilarity
{
    public function __construct(private readonly EmbeddingProvider $provider) {}

    /**
     * Rank a material query against natural-language intent.
     *
     * @param  Builder<Material>  $query
     * @return Builder<Material>
     */
    public function toText(Builder $query, string $text): Builder
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        if ($text === '') {
            return $query->orderBy('materials.name');
        }

        $cacheKey = 'material-query-embedding:'.hash('sha256', implode('|', [
            $this->provider->name(),
            $this->provider->model(),
            (string) $this->provider->dimensions(),
            $text,
        ]));

        $literal = Cache::remember($cacheKey, now()->addDay(), function () use ($text): string {
            return EmbeddingVector::literal(
                $this->provider->embed(new EmbeddingInput($text)),
                $this->provider->dimensions(),
            );
        });

        return $this->rank($query, $literal);
    }

    /**
     * Rank a material query against an already-indexed material.
     *
     * @param  Builder<Material>  $query
     * @return Builder<Material>
     */
    public function toMaterial(Builder $query, Material $material): Builder
    {
        $literal = Embedding::query()
            ->whereMorphedTo('embeddable', $material)
            ->where('kind', MaterialEmbeddingDocuments::KIND)
            ->where('provider', $this->provider->name())
            ->where('model', $this->provider->model())
            ->value('embedding');

        if (! is_string($literal) || $literal === '') {
            return $query->whereRaw('1 = 0');
        }

        return $this->rank($query->whereKeyNot($material->getKey()), $literal);
    }

    /**
     * @param  Builder<Material>  $query
     * @return Builder<Material>
     */
    private function rank(Builder $query, string $literal): Builder
    {
        return $query
            ->join('embeddings as similarity_embeddings', function (JoinClause $join): void {
                $join->on('similarity_embeddings.embeddable_id', '=', 'materials.id')
                    ->where('similarity_embeddings.embeddable_type', (new Material)->getMorphClass())
                    ->where('similarity_embeddings.kind', MaterialEmbeddingDocuments::KIND)
                    ->where('similarity_embeddings.provider', $this->provider->name())
                    ->where('similarity_embeddings.model', $this->provider->model());
            })
            ->select('materials.*')
            ->selectRaw('1 - (similarity_embeddings.embedding <=> CAST(? AS vector)) AS similarity_score', [$literal])
            ->orderByRaw('similarity_embeddings.embedding <=> CAST(? AS vector)', [$literal]);
    }
}
