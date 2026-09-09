<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\MaterialResource;
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
            'category' => ['nullable', 'string', 'max:8'],
            'supplier' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'in:draft,active,archived'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $materials = Material::query()
            ->visibleTo($request->user())
            ->search((string) ($validated['q'] ?? ''))
            ->when(isset($validated['category']), fn ($query) => $query->whereRelation('category', 'code', strtoupper($validated['category'])))
            ->when(isset($validated['supplier']), fn ($query) => $query->whereRelation('supplier', 'code', strtoupper($validated['supplier'])))
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->with(['category', 'supplier', 'currentVersion', 'variants.attributes.type'])
            ->orderBy('name')
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
