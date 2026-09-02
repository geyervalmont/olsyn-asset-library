<?php

namespace App\Actions\Tenants;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class SwitchCurrentTenant
{
    public const TENANT_SESSION_KEY = 'ensure_valid_tenant_session_tenant_id';

    public function handle(User $user, Tenant $tenant, Request $request): void
    {
        if (! $user->tenants()->whereKey($tenant->getKey())->exists()) {
            throw new AuthorizationException('You are not a member of this tenant.');
        }

        $user->forceFill(['current_tenant_id' => $tenant->getKey()])->save();
        $user->unsetRelation('currentTenant')->unsetRelation('roles')->unsetRelation('permissions');

        if ($request->hasSession()) {
            $request->session()->forget(self::TENANT_SESSION_KEY);
        }

        $tenant->makeCurrent();
    }
}
