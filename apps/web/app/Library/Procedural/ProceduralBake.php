<?php

namespace App\Library\Procedural;

final readonly class ProceduralBake
{
    /**
     * @param  list<ProceduralAsset>  $assets
     */
    public function __construct(
        public string $generator,
        public string $generatorVersion,
        public string $definitionDigest,
        public int $widthPx,
        public int $heightPx,
        public float $widthMm,
        public float $heightMm,
        public array $assets,
    ) {}
}
