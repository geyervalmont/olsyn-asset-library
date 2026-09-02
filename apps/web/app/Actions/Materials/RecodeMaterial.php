<?php

namespace App\Actions\Materials;

use App\Library\CodeTokenizer;
use App\Library\MaterialCodes;
use App\Models\Category;
use App\Models\Material;
use App\Models\Supplier;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;

/**
 * Explicitly change a material's readable code, for example after a
 * recategorisation. Old codes become aliases so nothing downstream breaks.
 */
class RecodeMaterial
{
    public function __construct(private readonly MaterialCodes $codes) {}

    public function handle(
        Material $material,
        ?Category $category = null,
        ?Supplier $supplier = null,
        ?string $productToken = null,
        string $reason = 'recode',
    ): Material {
        return DB::transaction(function () use ($material, $category, $supplier, $productToken, $reason): Material {
            if ($category !== null) {
                $material->category()->associate($category);
            }

            if ($supplier !== null) {
                $material->supplier()->associate($supplier);
            }

            $material->unsetRelation('category')->unsetRelation('supplier');

            $base = implode(MaterialCodes::SEPARATOR, [
                strtoupper($material->category->code),
                $material->supplier_id === null ? MaterialCodes::IN_HOUSE : $material->supplier->code,
                CodeTokenizer::token($productToken ?? $material->name),
            ]);

            $code = $this->codes->unique(
                $base,
                fn (string $candidate): bool => $candidate !== $material->code && Material::resolveCode($candidate) !== null,
            );

            $material->applyRecode($code, $reason);

            $material->variants()->get()->each(function (Variant $variant) use ($material, $reason): void {
                $variant->setRelation('material', $material);
                $variant->token = $this->codes->variantToken($variant);
                $variant->applyRecode($this->codes->variantCode($variant), $reason);
            });

            return $material->refresh();
        });
    }
}
