<?php

namespace App\Library\Conversion;

use App\Models\File;
use App\Models\QualityTier;
use App\Models\Representation;
use App\Models\Target;

/**
 * Derives a target representation from the canonical one.
 */
interface Converter
{
    public function supports(Target $target): bool;

    public function tool(): string;

    public function version(): string;

    /**
     * Files for the derived representation, keyed by map role slug.
     *
     * @return array<string, File>
     */
    public function convert(Representation $canonical, Target $target, QualityTier $quality): array;
}
