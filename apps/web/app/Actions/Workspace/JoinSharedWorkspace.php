<?php

namespace App\Actions\Workspace;

use App\Actions\Tenants\AddTenantMember;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkspaceAccess;
use App\Services\OlsynAccess;
use App\Support\SharedWorkspace;
use Illuminate\Support\Facades\DB;

final class JoinSharedWorkspace
{
    public function handle(User $user): ?Tenant
    {
        $tenant = app(SharedWorkspace::class)->tenant();
        if ($tenant === null || ! $user->hasVerifiedEmail()) {
            return null;
        }
        if ($user->canAccessTenant($tenant)) {
            $this->select($user, $tenant);

            return $tenant;
        }
        $automatic = config('opal.workspace.auto_join') && config('olsyn_access.enabled')
            && app(OlsynAccess::class)->allows($user, 'materials.view');
        $role = $automatic && app(OlsynAccess::class)->allows($user, 'materials.contribute') ? Role::Editor : Role::Viewer;

        return DB::transaction(function () use ($tenant, $user, $automatic, $role): ?Tenant {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $entry = WorkspaceAccess::query()->where('tenant_id', $tenant->id)->where('email', strtolower($user->email))->first();
            $pending = $entry?->status === 'pending' && $entry->expires_at?->isFuture();
            // Removal is durable: automatic enrollment must not undo it on the
            // next request. An explicit, fresh invitation can restore access.
            if (! $pending && (WorkspaceAccess::query()->where('tenant_id', $tenant->id)->where('status', 'removed')
                ->where(fn ($query) => $query->where('email', strtolower($user->email))->orWhere('user_id', $user->id))->exists()
                || ! $automatic)) {
                return null;
            }
            // Recheck after acquiring the workspace lock so parallel first
            // requests cannot reset a role that an administrator just changed.
            if (! $user->isMemberOf($tenant)) {
                $assigned = $pending ? Role::from($entry->role) : $role;
                app(AddTenantMember::class)->handle($tenant, $user, $assigned);
                WorkspaceAccess::updateOrCreate(['tenant_id' => $tenant->id, 'email' => strtolower($user->email)], [
                    'role' => $assigned->value, 'status' => 'active', 'user_id' => $user->id, 'expires_at' => null,
                ]);
            }
            $this->select($user, $tenant);

            return $tenant;
        });
    }

    private function select(User $user, Tenant $tenant): void
    {
        if ($user->current_tenant_id !== $tenant->id) {
            $user->forceFill(['current_tenant_id' => $tenant->id])->save();
        }
        $user->setRelation('currentTenant', $tenant);
    }
}
