<?php

namespace App\Library\Derivatives;

/** One consumer-ready file emitted from a canonical package. */
readonly class DerivedAsset
{
    public function __construct(
        public string $role,
        public string $contents,
        public string $name,
        public string $mimeType,
        public ?string $colourSpace = null,
    ) {}
}
