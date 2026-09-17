<?php

namespace App\Http\Controllers;

use App\Library\Previews\MaterialPreviews;
use App\Library\QrCodes\MaterialQrCodes;
use App\Models\Material;
use App\Models\Variant;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PublicMaterialController
{
    public function __invoke(Request $request, Material $material, MaterialPreviews $previews, MaterialQrCodes $qrCodes): Response
    {
        $material->load(['category', 'supplier']);
        $variants = $material->variants()->orderBy('position')->orderBy('id')->get();
        $variant = $this->selectedVariant($request, $material, $variants->first());
        $preview = $variant === null ? null : ($previews->variantFilesFor(collect([$variant]))[$variant->getKey()] ?? null);
        $hex = $variant?->dominant_hex;

        if (! is_string($hex) || preg_match('/^#[0-9a-f]{6}$/i', $hex) !== 1) {
            $hex = MaterialPreviews::fallbackHex($variant->code ?? $material->code);
        }

        return response()->view('materials.public', [
            'material' => $material,
            'variant' => $variant,
            'variantsCount' => $variants->count(),
            'previewUrl' => $preview === null ? null : $qrCodes->previewUrl($material, $variant),
            'fallbackHex' => $hex,
        ])->withHeaders([
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }

    private function selectedVariant(Request $request, Material $material, ?Variant $fallback): ?Variant
    {
        if (! $request->has('variant')) {
            return $fallback;
        }

        $variantId = filter_var($request->query('variant'), FILTER_VALIDATE_INT);

        abort_if($variantId === false, 404);

        return $material->variants()->findOrFail($variantId);
    }
}
