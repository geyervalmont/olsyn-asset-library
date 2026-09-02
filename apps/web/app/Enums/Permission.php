<?php

namespace App\Enums;

/**
 * Permissions are defined once, globally. Roles grant them per tenant.
 */
enum Permission: string
{
    case ManageTenant = 'tenant.manage';
    case ManageMembers = 'members.manage';
    case ViewMaterials = 'materials.view';
    case ContributeMaterials = 'materials.contribute';
    case ReviewMaterials = 'materials.review';
    case PublishMaterials = 'materials.publish';

    public function label(): string
    {
        return match ($this) {
            self::ManageTenant => 'Manage workspace settings',
            self::ManageMembers => 'Invite and remove members',
            self::ViewMaterials => 'View materials',
            self::ContributeMaterials => 'Add and edit materials and sources',
            self::ReviewMaterials => 'Approve or reject candidates',
            self::PublishMaterials => 'Publish approved representations',
        };
    }
}
