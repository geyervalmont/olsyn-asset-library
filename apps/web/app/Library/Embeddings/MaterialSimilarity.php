<?php

namespace App\Library\Embeddings;

use App\Models\Embedding;
use App\Models\Material;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
     * Rank materials by their closest image-only variant and expose that exact
     * colourway to the caller. Materials and variants without visual vectors
     * are excluded, as are candidates below the configured similarity floor.
     *
     * @param  Builder<Material>  $query
     * @return Builder<Material>
     */
    public function toAppearance(Builder $query, Variant $variant): Builder
    {
        $literal = Embedding::query()
            ->whereMorphedTo('embeddable', $variant)
            ->where('kind', VariantVisualEmbeddingDocuments::KIND)
            ->where('provider', $this->provider->name())
            ->where('model', $this->provider->model())
            ->value('embedding');

        if (! is_string($literal) || $literal === '') {
            return $query->whereRaw('1 = 0');
        }

        $minimum = max(-1, min(1, (float) config('opal.embeddings.appearance_min_similarity', 0.55)));
        $matches = DB::table('variants as visual_variants')
            ->join('embeddings as visual_embeddings', function (JoinClause $join): void {
                $join->on('visual_embeddings.embeddable_id', '=', 'visual_variants.id')
                    ->where('visual_embeddings.embeddable_type', (new Variant)->getMorphClass())
                    ->where('visual_embeddings.kind', VariantVisualEmbeddingDocuments::KIND)
                    ->where('visual_embeddings.provider', $this->provider->name())
                    ->where('visual_embeddings.model', $this->provider->model());
            })
            ->where('visual_variants.id', '!=', $variant->getKey())
            ->whereRaw('1 - (visual_embeddings.embedding <=> CAST(? AS vector)) >= ?', [$literal, $minimum])
            ->groupBy('visual_variants.material_id')
            ->select('visual_variants.material_id')
            ->selectRaw(
                '(array_agg(visual_variants.id ORDER BY visual_embeddings.embedding <=> CAST(? AS vector)))[1] AS matched_variant_id',
                [$literal],
            )
            ->selectRaw(
                'MAX(1 - (visual_embeddings.embedding <=> CAST(? AS vector))) AS similarity_score',
                [$literal],
            );

        $this->selectMaterials($query);

        return $query
            ->whereKeyNot($variant->material_id)
            ->joinSub($matches, 'appearance_matches', fn (JoinClause $join): JoinClause => $join
                ->on('appearance_matches.material_id', '=', 'materials.id'))
            ->addSelect(['appearance_matches.similarity_score', 'appearance_matches.matched_variant_id'])
            ->orderByDesc('appearance_matches.similarity_score');
    }

    /**
     * @param  Builder<Material>  $query
     * @return Builder<Material>
     */
    private function rank(Builder $query, string $literal): Builder
    {
        $this->selectMaterials($query);

        return $query
            ->join('embeddings as similarity_embeddings', function (JoinClause $join): void {
                $join->on('similarity_embeddings.embeddable_id', '=', 'materials.id')
                    ->where('similarity_embeddings.embeddable_type', (new Material)->getMorphClass())
                    ->where('similarity_embeddings.kind', MaterialEmbeddingDocuments::KIND)
                    ->where('similarity_embeddings.provider', $this->provider->name())
                    ->where('similarity_embeddings.model', $this->provider->model());
            })
            ->selectRaw('1 - (similarity_embeddings.embedding <=> CAST(? AS vector)) AS similarity_score', [$literal])
            ->orderByRaw('similarity_embeddings.embedding <=> CAST(? AS vector)', [$literal]);
    }

    /** @param Builder<Material> $query */
    private function selectMaterials(Builder $query): void
    {
        if ($query->getQuery()->columns === null) {
            $query->select('materials.*');
        }
    }
}
