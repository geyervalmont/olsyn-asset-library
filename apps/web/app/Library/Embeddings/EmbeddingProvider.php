<?php

namespace App\Library\Embeddings;

interface EmbeddingProvider
{
    public function name(): string;

    public function model(): string;

    public function dimensions(): int;

    /**
     * @return list<float>
     */
    public function embed(EmbeddingInput $input): array;
}
