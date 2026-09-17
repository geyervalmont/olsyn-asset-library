<?php

namespace App\Http\Resources;

use App\Models\Material;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Material
 */
class MaterialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $matchedVariant = $this->relationLoaded('variants')
            ? $this->variants->firstWhere('id', (int) $this->getAttribute('matched_variant_id'))
            : null;

        return [
            'code' => $this->code,
            'name' => $this->name,
            'category' => ['code' => $this->category->code, 'name' => $this->category->name],
            'supplier' => $this->supplier === null ? null : ['code' => $this->supplier->code, 'name' => $this->supplier->name],
            'collection' => $this->collection,
            'supplier_product_code' => $this->supplier_product_code,
            'description' => $this->description,
            'material_type' => $this->material_type,
            'form' => $this->form,
            'tile_width_mm' => $this->tile_width_mm === null ? null : (float) $this->tile_width_mm,
            'tile_height_mm' => $this->tile_height_mm === null ? null : (float) $this->tile_height_mm,
            'status' => $this->status->value,
            'visibility' => $this->visibility->value,
            'current_version' => $this->currentVersion?->number,
            'similarity' => $this->getAttribute('similarity_score') === null ? null : round((float) $this->getAttribute('similarity_score'), 4),
            'matched_variant' => $matchedVariant === null ? null : new VariantResource($matchedVariant),
            'variants' => VariantResource::collection($this->whenLoaded('variants')),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
