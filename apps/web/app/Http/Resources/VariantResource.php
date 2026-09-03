<?php

namespace App\Http\Resources;

use App\Models\Variant;
use App\Models\VariantAttribute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Variant
 */
class VariantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'material_code' => $this->whenLoaded('material', fn () => $this->material->code),
            'attributes' => $this->whenLoaded('attributes', fn () => $this->attributes->map(fn (VariantAttribute $attribute): array => [
                'type' => $attribute->type->slug,
                'value' => $attribute->value,
                'supplier_code' => $attribute->supplier_code,
            ])->values()->all()),
            'tile_width_mm' => $this->effectiveTileWidthMm() === null ? null : (float) $this->effectiveTileWidthMm(),
            'tile_height_mm' => $this->effectiveTileHeightMm() === null ? null : (float) $this->effectiveTileHeightMm(),
            'dominant_hex' => $this->dominant_hex,
            'colour_family' => $this->colour_family,
            'representations' => RepresentationResource::collection($this->whenLoaded('representations')),
        ];
    }
}
