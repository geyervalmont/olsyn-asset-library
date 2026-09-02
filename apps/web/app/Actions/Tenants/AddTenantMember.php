<?php

namespace App\Actions\Tenants;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;

class AddTenantMember
{
    /**
     * Attach a user to a tenant with exactly one tenant role.
     */
    public function handle(Tenant $tenant, User $user, Role $role): void
    {
        $user->tenants()->syncWithoutDetaching([$tenant->getKey()]);

        $tenant->execute(function () use ($user, $role): void {
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $user->syncRoles([$role->value]);
        });

        $user->unsetRelation('tenants')->unsetRelation('roles')->unsetRelation('permissions');

        if ($user->current_tenant_id === null) {
            $user->forceFill(['current_tenant_id' => $tenant->getKey()])->save();
        }
    }
}
