<?php

namespace App\Actions\Visibility;

use App\Models\Drive;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\User;

class RevokeMaterialAccess
{
    public function handle(Material $material, User|Drive|Tenant $grantee): void
    {
        $material->grants()
            ->where('grantee_type', $grantee->getMorphClass())
            ->where('grantee_id', $grantee->getKey())
            ->delete();
    }
}
