<?php

namespace App\Actions\Workspace;

use App\Actions\Tenants\AddTenantMember;
use App\Actions\Tenants\RemoveTenantMember;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkspaceAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ManageTeam
{
    public function authorize(User $actor, Tenant $tenant): void
    {
        abort_unless($actor->canAccessTenant($tenant) && $tenant->execute(function () use ($actor): bool {
            $actor->unsetRelation('roles')->unsetRelation('permissions');

            return $actor->can('members.manage');
        }), 403);
    }

    public function invite(User $actor, Tenant $tenant, string $email, Role $role): void
    {
        $email = strtolower(trim($email));
        DB::transaction(function () use ($actor, $tenant, $email, $role): void {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $tenant);
            $existing = User::query()->whereRaw('lower(email) = ?', [$email])->first();
            if ($existing !== null && $existing->isMemberOf($tenant)) {
                throw ValidationException::withMessages(['email' => 'This person is already on the team. Change their role below.']);
            }
            WorkspaceAccess::updateOrCreate(['tenant_id' => $tenant->id, 'email' => $email], [
                'role' => $role->value, 'status' => 'pending', 'user_id' => null,
                'changed_by' => $actor->id, 'expires_at' => now()->addDays(7),
            ]);
        });
    }

    public function changeRole(User $actor, Tenant $tenant, User $member, Role $role): void
    {
        DB::transaction(function () use ($actor, $tenant, $member, $role): void {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $tenant);
            abort_unless($member->isMemberOf($tenant), 404);
            abort_if($member->isSuperAdmin(), 403, 'Global administrators are managed outside this team.');
            if ($role !== Role::Admin) {
                $this->protectLastAdmin($tenant, $member);
            }
            app(AddTenantMember::class)->handle($tenant, $member, $role);
            WorkspaceAccess::updateOrCreate(['tenant_id' => $tenant->id, 'email' => strtolower($member->email)], [
                'role' => $role->value, 'status' => 'active', 'user_id' => $member->id,
                'changed_by' => $actor->id, 'expires_at' => null,
            ]);
        });
    }

    public function remove(User $actor, Tenant $tenant, User $member): void
    {
        DB::transaction(function () use ($actor, $tenant, $member): void {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $tenant);
            abort_unless($member->isMemberOf($tenant), 404);
            abort_if($member->isSuperAdmin(), 403, 'Global administrators are managed outside this team.');
            $this->protectLastAdmin($tenant, $member);
            WorkspaceAccess::updateOrCreate(['tenant_id' => $tenant->id, 'email' => strtolower($member->email)], [
                'role' => ($member->roleIn($tenant) ?? Role::Viewer)->value, 'status' => 'removed',
                'user_id' => $member->id, 'changed_by' => $actor->id, 'expires_at' => null,
            ]);
            app(RemoveTenantMember::class)->handle($tenant, $member);
        });
    }

    public function cancel(User $actor, Tenant $tenant, int $id): void
    {
        DB::transaction(function () use ($actor, $tenant, $id): void {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $tenant);
            $entry = WorkspaceAccess::query()->where('tenant_id', $tenant->id)->where('status', 'pending')->findOrFail($id);
            $entry->update(['status' => 'removed', 'changed_by' => $actor->id, 'expires_at' => null]);
        });
    }

    private function protectLastAdmin(Tenant $tenant, User $member): void
    {
        if ($member->roleIn($tenant) === Role::Admin && $tenant->execute(fn () => $tenant->users()->role(Role::Admin->value)->count()) <= 1) {
            throw ValidationException::withMessages(['team' => 'Keep at least one team administrator. Assign another administrator first.']);
        }
    }
}
