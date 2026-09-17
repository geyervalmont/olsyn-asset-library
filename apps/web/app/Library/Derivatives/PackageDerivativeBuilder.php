<?php

namespace App\Library\Derivatives;

use App\Models\Package;
use App\Models\QualityTier;
use App\Models\Target;

/** Converts the canonical USDZ into a disposable consumer cache. */
interface PackageDerivativeBuilder
{
    public function name(): string;

    public function version(): string;

    public function available(): bool;

    public function supports(Target $target): bool;

    public function build(Package $package, Target $target, QualityTier $quality): BuiltPackageDerivative;
}
