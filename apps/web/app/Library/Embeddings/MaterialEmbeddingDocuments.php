<?php

namespace App\Library\Embeddings;

use App\Library\Previews\MaterialPreviews;
use App\Models\Material;
use App\Models\Variant;
use Illuminate\Support\Str;

/**
 * Produces the stable, provider-independent description used to index a
 * material. Reordering JSON keys or changing an image reference cannot make
 * an unchanged document look stale accidentally.
 */
final class MaterialEmbeddingDocuments
{
    public const PROFILE = 'material-multimodal-v1';

    public const KIND = 'material_similarity';

    public function __construct(private readonly MaterialPreviews $previews) {}

    public function for(Material $material): MaterialEmbeddingDocument
    {
        $material->loadMissing(['category', 'supplier', 'tags', 'variants.attributes.type']);
        $preview = $this->previews->filesFor($material->newCollection([$material]))[$material->getKey()] ?? null;
        $specifications = $material->specifications ?? [];
        ksort($specifications);

        $variantText = $material->variants
            ->map(fn (Variant $variant): string => collect([
                $variant->name,
                $variant->colour_family,
                $variant->attributes->map(fn ($attribute): string => $attribute->type->name.': '.$attribute->value)->implode(', '),
            ])->filter()->implode(' · '))
            ->filter()
            ->implode('; ');

        $lines = array_filter([
            'Material: '.$material->name,
            'Category: '.$material->category->name,
            $material->material_type ? 'Type: '.$material->material_type : null,
            $material->form ? 'Form: '.$material->form : null,
            $material->supplier ? 'Supplier: '.$material->supplier->name : 'Supplier: in-house',
            $material->collection ? 'Collection: '.$material->collection : null,
            $material->description ? 'Description: '.$material->description : null,
            $material->tags->isNotEmpty() ? 'Tags: '.$material->tags->pluck('name')->implode(', ') : null,
            $variantText !== '' ? 'Variants: '.$variantText : null,
            $material->install_pattern ? 'Installation: '.$material->install_pattern : null,
            $specifications !== [] ? 'Specifications: '.json_encode($specifications, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
        ]);

        // Titan Multimodal accepts 256 text tokens. Important semantic fields
        // come first; the image carries the appearance independently.
        $text = Str::limit(implode("\n", $lines), 1100, '');
        $digest = hash('sha256', json_encode([
            'profile' => self::PROFILE,
            'text' => $text,
            'image' => $preview?->sha256,
        ], JSON_THROW_ON_ERROR));

        return new MaterialEmbeddingDocument($text, $digest, $preview);
    }
}
