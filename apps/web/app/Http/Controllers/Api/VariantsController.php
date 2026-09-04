<?php

namespace App\Http\Controllers\Api;

use App\Actions\Platforms\ResolvePlatformVariant;
use App\Http\Resources\VariantResource;
use App\Models\Variant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VariantsController
{
    /**
     * One variant with its representations and file URLs.
     */
    public function show(Request $request, string $code): VariantResource
    {
        $variant = Variant::resolveCode($code);

        abort_if($variant === null || ! $variant->material->isVisibleTo($request->user()), 404);

        return new VariantResource($this->load($variant));
    }

    /**
     * Resolve a platform reference to a variant.
     *
     * Accepts a platform id, a platform material name, or any string that
     * contains a variant code, e.g. a Revit material named
     * "Academix Ashen [CPT-TARKETT-ACADEMIX-ASHEN]".
     */
    public function resolve(Request $request, ResolvePlatformVariant $resolve): VariantResource
    {
        $validated = $request->validate([
            'platform' => ['required', 'string', 'exists:platforms,slug'],
            'reference' => ['required', 'string', 'max:500'],
        ]);

        $variant = $resolve->handle($validated['platform'], $validated['reference']);

        abort_if($variant === null || ! $variant->material->isVisibleTo($request->user()), 404, 'No variant matches that reference.');

        return new VariantResource($this->load($variant));
    }

    /**
     * Resolve many platform references at once.
     *
     * A Sync in Revit sends every material's candidate references in one
     * request; the answer maps each reference to a variant or null.
     */
    public function resolveMany(Request $request, ResolvePlatformVariant $resolve): JsonResponse
    {
        $validated = $request->validate([
            'platform' => ['required', 'string', 'exists:platforms,slug'],
            'references' => ['required', 'array', 'max:500'],
            'references.*' => ['string', 'max:500'],
        ]);

        $resolved = [];
        foreach (array_values(array_unique($validated['references'])) as $reference) {
            $variant = $resolve->handle($validated['platform'], $reference);
            $resolved[$reference] = $variant !== null && $variant->material->isVisibleTo($request->user())
                ? [
                    'code' => $variant->code,
                    'name' => $variant->name,
                    'material_code' => $variant->material->code,
                    'material_name' => $variant->material->name,
                ]
                : null;
        }

        return response()->json(['data' => (object) $resolved]);
    }

    private function load(Variant $variant): Variant
    {
        return $variant->load(['material', 'attributes.type', 'representations.target', 'representations.quality', 'representations.representationFiles.role', 'representations.representationFiles.file']);
    }
}
