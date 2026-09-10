<?php

namespace App\Library\Procedural;

use RuntimeException;

final class PendingProceduralBaker implements ProceduralBaker
{
    public function available(): bool
    {
        return false;
    }

    public function bake(array $definition): ProceduralBake
    {
        throw new RuntimeException('No USD toolbox is installed; set OPAL_TOOLBOX_BIN before baking procedural materials.');
    }
}
