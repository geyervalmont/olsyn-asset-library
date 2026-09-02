<?php

namespace App\Actions\Tenants;

use App\Models\Tenant;
use App\Models\User;

class RemoveTenantMember
{
    public function handle(Tenant $tenant, User $user): void
    {
        $tenant->execute(function () use ($user): void {
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $user->syncRoles([]);
        });

        $user->tenants()->detach($tenant->getKey());
        $user->unsetRelation('tenants')->unsetRelation('roles')->unsetRelation('permissions');

        if ((string) $user->current_tenant_id === (string) $tenant->getKey()) {
            $user->forceFill(['current_tenant_id' => null])->save();
        }
    }
}
