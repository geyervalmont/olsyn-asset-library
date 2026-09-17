<?php

namespace App\Library\Embeddings;

use App\Models\File;

final readonly class VariantVisualEmbeddingDocument
{
    public function __construct(
        public string $digest,
        public ?File $image,
    ) {}
}
