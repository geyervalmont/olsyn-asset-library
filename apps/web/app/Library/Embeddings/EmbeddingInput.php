<?php

namespace App\Library\Embeddings;

final readonly class EmbeddingInput
{
    public function __construct(
        public ?string $text = null,
        public ?string $image = null,
    ) {}
}
