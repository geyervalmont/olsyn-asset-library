<?php

namespace App\Library\Packaging;

/**
 * What the toolbox produced: the bytes, and an honest account of what did not
 * survive the conversion.
 */
readonly class BuiltPackage
{
    /**
     * @param  list<string>  $tiers
     * @param  list<array<string, mixed>>  $losses
     */
    public function __construct(
        public string $path,
        public string $sha256,
        public int $bytes,
        public array $tiers,
        public array $losses,
        public string $builder,
        public string $builderVersion,
    ) {}
}
