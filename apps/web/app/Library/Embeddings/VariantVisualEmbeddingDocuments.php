<?php

namespace App\Library\Embeddings;

use App\Library\Previews\MaterialPreviews;
use App\Models\Variant;

/** Build the image-only document used for strict appearance similarity. */
final class VariantVisualEmbeddingDocuments
{
    public const PROFILE = 'variant-visual-v1';

    public const KIND = 'variant_visual';

    public function __construct(private readonly MaterialPreviews $previews) {}

    public function for(Variant $variant): VariantVisualEmbeddingDocument
    {
        $preview = $this->previews->variantFilesFor(collect([$variant]))[$variant->getKey()] ?? null;
        $digest = hash('sha256', json_encode([
            'profile' => self::PROFILE,
            'image' => $preview?->sha256,
        ], JSON_THROW_ON_ERROR));

        return new VariantVisualEmbeddingDocument($digest, $preview);
    }
}
