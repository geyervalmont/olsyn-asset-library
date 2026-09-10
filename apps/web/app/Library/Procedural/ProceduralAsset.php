<?php

namespace App\Library\Procedural;

final readonly class ProceduralAsset
{
    public function __construct(
        public string $role,
        public string $filename,
        public string $extension,
        public string $mimeType,
        public string $sha256,
        public string $contents,
    ) {}
}
