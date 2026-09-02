<?php

namespace App\Actions\Visibility;

use App\Enums\Visibility;
use App\Models\Material;

class SetMaterialVisibility
{
    public function handle(Material $material, Visibility $visibility): Material
    {
        $material->forceFill(['visibility' => $visibility])->save();

        return $material;
    }
}
