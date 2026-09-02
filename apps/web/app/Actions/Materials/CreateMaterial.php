<?php

namespace App\Actions\Materials;

use App\Models\Material;
use Illuminate\Support\Facades\DB;

class CreateMaterial
{
    public function __construct(private readonly AddVariant $addVariant) {}

    /**
     * Create a material with at least one variant.
     *
     * @param  array<string, mixed>  $attributes  material columns (name and category_id required)
     * @param  list<array{name?: string|null, attributes?: array<string, mixed>, overrides?: array<string, mixed>}>  $variants
     * @param  list<string>  $tags
     */
    public function handle(array $attributes, array $variants = [], array $tags = []): Material
    {
        return DB::transaction(function () use ($attributes, $variants, $tags): Material {
            $material = Material::create($attributes);

            if ($tags !== []) {
                $material->syncTagNames($tags);
            }

            foreach ($variants === [] ? [[]] : $variants as $variant) {
                /** @var array<string, string|array{value: string, supplier_code?: string|null, supplier_name?: string|null, ref_variant_id?: int|null}> $variantAttributes */
                $variantAttributes = $variant['attributes'] ?? [];

                $this->addVariant->handle(
                    $material,
                    $variantAttributes,
                    $variant['name'] ?? null,
                    $variant['overrides'] ?? [],
                );
            }

            $material->refreshSearchText();

            return $material->refresh();
        });
    }
}
