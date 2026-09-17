<?php

namespace App\Library\Derivatives;

/** The transient result of exporting one package for one consumer. */
readonly class BuiltPackageDerivative
{
    /**
     * @param  list<DerivedAsset>  $assets
     * @param  list<array<string, mixed>>  $losses
     */
    public function __construct(
        public array $assets,
        public array $losses = [],
    ) {}
}
