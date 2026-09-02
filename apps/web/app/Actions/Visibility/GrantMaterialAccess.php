<?php

namespace App\Actions\Visibility;

use App\Models\Drive;
use App\Models\Material;
use App\Models\MaterialGrant;
use App\Models\Tenant;
use App\Models\User;

class GrantMaterialAccess
{
    public function handle(Material $material, User|Drive|Tenant $grantee, ?User $grantedBy = null): MaterialGrant
    {
        return $material->grants()->firstOrCreate(
            ['grantee_type' => $grantee->getMorphClass(), 'grantee_id' => $grantee->getKey()],
            ['granted_by_user_id' => $grantedBy?->getKey()],
        );
    }
}
