<?php

namespace App\Actions\Tenants;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkspaceAccess;
use App\Support\SharedWorkspace;

class RemoveTenantMember
{
    public function handle(Tenant $tenant, User $user): void
    {
        if (config('opal.workspace.single') && app(SharedWorkspace::class)->tenant()?->is($tenant)) {
            WorkspaceAccess::updateOrCreate(['tenant_id' => $tenant->id, 'email' => strtolower($user->email)], [
                'role' => ($user->roleIn($tenant) ?? Role::Viewer)->value,
                'status' => 'removed', 'user_id' => $user->id, 'expires_at' => null,
            ]);
        }
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
