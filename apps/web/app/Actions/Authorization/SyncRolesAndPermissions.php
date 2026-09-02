<?php

namespace App\Actions\Authorization;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Tenant;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create or update the global permission and role definitions.
 *
 * Roles are created without a tenant so that Spatie resolves them in every
 * team context; assignments (model_has_roles) remain tenant-specific.
 */
class SyncRolesAndPermissions
{
    public const GUARD = 'web';

    public function handle(): void
    {
        $previousTenant = Tenant::current();
        Tenant::forgetCurrent();

        try {
            foreach (Permission::cases() as $permission) {
                PermissionModel::findOrCreate($permission->value, self::GUARD);
            }

            foreach (Role::cases() as $role) {
                $model = RoleModel::query()
                    ->where('name', $role->value)
                    ->where('guard_name', self::GUARD)
                    ->whereNull('tenant_id')
                    ->first();

                $model ??= RoleModel::create([
                    'name' => $role->value,
                    'guard_name' => self::GUARD,
                ]);

                $model->syncPermissions(
                    array_map(fn (Permission $permission): string => $permission->value, $role->permissions()),
                );
            }
        } finally {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $previousTenant?->makeCurrent();
        }
    }
}
