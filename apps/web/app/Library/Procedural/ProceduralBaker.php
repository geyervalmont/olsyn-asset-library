<?php

namespace App\Library\Procedural;

interface ProceduralBaker
{
    public function available(): bool;

    /**
     * @param  array<string, mixed>  $definition
     */
    public function bake(array $definition): ProceduralBake;
}
