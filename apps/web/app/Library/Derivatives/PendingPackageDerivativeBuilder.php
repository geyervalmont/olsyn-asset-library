<?php

namespace App\Library\Derivatives;

use App\Models\Package;
use App\Models\QualityTier;
use App\Models\Target;
use RuntimeException;

/** Explicit unavailable state used when usd-toolbox is not installed. */
class PendingPackageDerivativeBuilder implements PackageDerivativeBuilder
{
    public function name(): string
    {
        return 'usd-toolbox';
    }

    public function version(): string
    {
        return 'unavailable';
    }

    public function available(): bool
    {
        return false;
    }

    public function supports(Target $target): bool
    {
        return false;
    }

    public function build(Package $package, Target $target, QualityTier $quality): BuiltPackageDerivative
    {
        throw new RuntimeException('No USD toolbox is available, so package derivatives cannot be built.');
    }
}
