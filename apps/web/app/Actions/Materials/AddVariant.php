<?php

namespace App\Actions\Materials;

use App\Models\Material;
use App\Models\Variant;
use App\Models\VariantType;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AddVariant
{
    /**
     * Add a variant described by typed attributes.
     *
     * Attributes are keyed by variant type slug. A value may be a string or an
     * array with value, supplier_code, supplier_name, and ref_variant_id.
     *
     * @param  array<string, string|array{value: string, supplier_code?: string|null, supplier_name?: string|null, ref_variant_id?: int|null}>  $attributes
     * @param  array<string, mixed>  $overrides  physical or colour columns on the variant
     */
    public function handle(Material $material, array $attributes = [], ?string $name = null, array $overrides = []): Variant
    {
        return DB::transaction(function () use ($material, $attributes, $name, $overrides): Variant {
            $resolved = $this->resolve($attributes);

            $variantName = $name ?? $this->nameFromAttributes($resolved);

            /** @var Variant $variant */
            $variant = $material->variants()->create([
                ...$overrides,
                'name' => $variantName,
                'position' => (int) $material->variants()->max('position') + 1,
            ]);

            foreach ($resolved as $row) {
                $variant->attributes()->create($row);
            }

            return $variant->refresh();
        });
    }

    /**
     * @param  array<string, string|array{value: string, supplier_code?: string|null, supplier_name?: string|null, ref_variant_id?: int|null}>  $attributes
     * @return list<array{variant_type_id: int, value: string, supplier_code: string|null, supplier_name: string|null, ref_variant_id: int|null}>
     */
    private function resolve(array $attributes): array
    {
        $rows = [];

        foreach ($attributes as $typeSlug => $input) {
            $type = VariantType::findBySlug($typeSlug);

            if ($type === null) {
                throw new InvalidArgumentException("Unknown variant type [{$typeSlug}].");
            }

            $input = is_string($input) ? ['value' => $input] : $input;

            $rows[] = [
                'variant_type_id' => (int) $type->getKey(),
                'value' => trim($input['value']),
                'supplier_code' => $input['supplier_code'] ?? null,
                'supplier_name' => $input['supplier_name'] ?? null,
                'ref_variant_id' => $input['ref_variant_id'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{variant_type_id: int, value: string, supplier_code: string|null, supplier_name: string|null, ref_variant_id: int|null}>  $rows
     */
    private function nameFromAttributes(array $rows): string
    {
        $values = array_map(fn (array $row): string => $row['value'], $rows);

        return $values === [] ? 'Default' : implode(', ', $values);
    }
}
