<?php

namespace App\Http\Controllers;

use App\Library\QrCodes\MaterialQrCodes;
use App\Models\Material;
use App\Models\Variant;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class PublicMaterialQrCodeController
{
    public function __invoke(Request $request, Material $material, MaterialQrCodes $qrCodes): Response
    {
        $variant = $this->variant($request, $material);
        $svg = $qrCodes->svg($qrCodes->publicUrl($material, $variant));
        $name = Str::slug($variant->code ?? $material->code).'-qr.svg';
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Content-Disposition' => $disposition.'; filename="'.$name.'"',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }

    private function variant(Request $request, Material $material): ?Variant
    {
        if (! $request->has('variant')) {
            return null;
        }

        $variantId = filter_var($request->query('variant'), FILTER_VALIDATE_INT);

        abort_if($variantId === false, 404);

        return $material->variants()->findOrFail($variantId);
    }
}
