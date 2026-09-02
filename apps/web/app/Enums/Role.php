<?php

namespace App\Enums;

/**
 * Tenant roles. Definitions are global; a user holds a role per tenant.
 *
 * Super-admin is deliberately not a role: it is a user flag that bypasses
 * authorization everywhere, independent of the current tenant.
 */
enum Role: string
{
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Editor => 'Editor',
            self::Viewer => 'Viewer',
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            self::Editor => [
                Permission::ViewMaterials,
                Permission::ContributeMaterials,
                Permission::ReviewMaterials,
            ],
            self::Viewer => [
                Permission::ViewMaterials,
            ],
        };
    }
}
