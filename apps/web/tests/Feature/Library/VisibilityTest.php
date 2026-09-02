<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Actions\Tenants\AddTenantMember;
use App\Actions\Visibility\GrantMaterialAccess;
use App\Actions\Visibility\RevokeMaterialAccess;
use App\Actions\Visibility\SetMaterialVisibility;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Models\Drive;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\LibrarySeeder;

beforeEach(function () {
    $this->seed(LibrarySeeder::class);
    app(SyncRolesAndPermissions::class)->handle();
});

test('restricted materials are only visible to their grantees', function () {
    $open = Material::factory()->create(['name' => 'Open']);
    $secret = Material::factory()->create(['name' => 'Secret']);
    app(SetMaterialVisibility::class)->handle($secret, Visibility::Restricted);

    $tenant = Tenant::factory()->create();
    $member = User::factory()->create();
    app(AddTenantMember::class)->handle($tenant, $member, Role::Viewer);
    $outsider = User::factory()->create();
    $superAdmin = User::factory()->superAdmin()->create();
    $drive = Drive::factory()->create();
    $tenantDrive = Drive::factory()->create(['tenant_id' => $tenant->getKey()]);

    $codes = fn ($query) => $query->orderBy('name')->pluck('name')->all();

    expect($codes(Material::query()->visibleTo($member)))->toBe(['Open'])
        ->and($codes(Material::query()->visibleTo($superAdmin)))->toBe(['Open', 'Secret'])
        ->and($codes(Material::query()->visibleToDrive($drive)))->toBe(['Open'])
        ->and($secret->isVisibleTo($member))->toBeFalse();

    app(GrantMaterialAccess::class)->handle($secret, $member, $superAdmin);
    app(GrantMaterialAccess::class)->handle($secret, $member);
    app(GrantMaterialAccess::class)->handle($secret, $drive);

    expect($secret->grants()->count())->toBe(2)
        ->and($codes(Material::query()->visibleTo($member)))->toBe(['Open', 'Secret'])
        ->and($codes(Material::query()->visibleTo($outsider)))->toBe(['Open'])
        ->and($codes(Material::query()->visibleToDrive($drive)))->toBe(['Open', 'Secret'])
        ->and($codes(Material::query()->visibleToDrive($tenantDrive)))->toBe(['Open']);

    app(RevokeMaterialAccess::class)->handle($secret, $member);
    app(GrantMaterialAccess::class)->handle($secret, $tenant);

    expect($codes(Material::query()->visibleTo($member)))->toBe(['Open', 'Secret'])
        ->and($codes(Material::query()->visibleTo($outsider)))->toBe(['Open'])
        ->and($codes(Material::query()->visibleToDrive($tenantDrive)))->toBe(['Open', 'Secret']);

    app(SetMaterialVisibility::class)->handle($secret, Visibility::Library);

    expect($codes(Material::query()->visibleTo($outsider)))->toBe(['Open', 'Secret']);
});
