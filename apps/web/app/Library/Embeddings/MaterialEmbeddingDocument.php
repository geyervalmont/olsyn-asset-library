<?php

namespace App\Library\Embeddings;

use App\Models\File;

final readonly class MaterialEmbeddingDocument
{
    public function __construct(
        public string $text,
        public string $digest,
        public ?File $image,
    ) {}
}
