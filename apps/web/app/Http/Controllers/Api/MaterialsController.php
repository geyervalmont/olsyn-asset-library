<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\MaterialResource;
use App\Library\Embeddings\MaterialSimilarity;
use App\Models\Material;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MaterialsController
{
    /**
     * Search the library.
     *
     * Full-text and fuzzy search over names, codes, suppliers, product codes,
     * colourways and tags; visibility rules apply to the calling user.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'mode' => ['nullable', 'in:keyword,semantic'],
            'similar_to' => ['nullable', 'string', 'max:96'],
            'category' => ['nullable', 'string', 'max:8'],
            'supplier' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'in:draft,active,archived'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Material::query()
            ->visibleTo($request->user())
            ->when(isset($validated['category']), fn ($query) => $query->whereRelation('category', 'code', strtoupper($validated['category'])))
            ->when(isset($validated['supplier']), fn ($query) => $query->whereRelation('supplier', 'code', strtoupper($validated['supplier'])))
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']));

        if (isset($validated['similar_to'])) {
            abort_unless(config('opal.embeddings.enabled'), 503, 'Material similarity is not enabled.');
            $source = Material::resolveCode($validated['similar_to']);
            abort_if($source === null || ! $source->isVisibleTo($request->user()), 404);
            $query = app(MaterialSimilarity::class)->toMaterial($query, $source);
        } elseif (($validated['mode'] ?? 'keyword') === 'semantic' && trim((string) ($validated['q'] ?? '')) !== '') {
            abort_unless(config('opal.embeddings.enabled'), 503, 'Semantic search is not enabled.');
            $query = app(MaterialSimilarity::class)->toText($query, (string) $validated['q']);
        } else {
            $query->search((string) ($validated['q'] ?? ''))->orderBy('name');
        }

        $materials = $query
            ->with(['category', 'supplier', 'currentVersion', 'variants.attributes.type'])
            ->paginate((int) ($validated['per_page'] ?? 25));

        return MaterialResource::collection($materials);
    }

    /**
     * One material with its variants and their representations.
     *
     * The code may be a current code or a retired alias.
     */
    public function show(Request $request, string $code): MaterialResource
    {
        $material = Material::resolveCode($code);

        abort_if($material === null || ! $material->isVisibleTo($request->user()), 404);

        $material->load(['category', 'supplier', 'currentVersion', 'variants.attributes.type', 'variants.representations.target', 'variants.representations.quality', 'variants.representations.representationFiles.role', 'variants.representations.representationFiles.file']);

        return new MaterialResource($material);
    }
}
