<?php

namespace App\Actions\Representations;

use App\Actions\Platforms\ResolvePlatformVariant;
use App\Models\Platform;
use App\Models\QualityTier;
use App\Models\Representation;
use App\Models\Target;
use App\Models\User;
use LogicException;

/**
 * "Give me the Omniverse material for this Revit material": resolve the
 * platform reference to a variant, then derive the requested target.
 */
class DeriveFromPlatformReference
{
    public function __construct(
        private readonly ResolvePlatformVariant $resolve,
        private readonly DeriveRepresentation $derive,
    ) {}

    public function handle(Platform|string $platform, string $reference, Target|string $target, QualityTier|string $quality, User|string|null $actor = null): Representation
    {
        $variant = $this->resolve->handle($platform, $reference)
            ?? throw new LogicException("No library variant matches [{$reference}].");

        return $this->derive->handle($variant, $target, $quality, $actor);
    }
}
