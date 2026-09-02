<?php

use App\Models\Tenant;
use App\Models\User;
use Spatie\Permission\Models\Role;

afterEach(function () {
    Tenant::forgetCurrent();
});

test('roles are isolated by the current tenant', function () {
    $firstTenant = Tenant::factory()->create();
    $secondTenant = Tenant::factory()->create();
    $user = User::factory()->create();

    $firstTenant->makeCurrent();
    $firstRole = Role::create(['name' => 'editor']);
    $user->assignRole($firstRole);

    expect($firstRole->tenant_id)->toBe($firstTenant->getKey())
        ->and($user->hasRole('editor'))->toBeTrue();

    $secondTenant->makeCurrent();
    $user->unsetRelation('roles')->unsetRelation('permissions');

    expect(getPermissionsTeamId())->toBe($secondTenant->getKey())
        ->and($user->hasRole('editor'))->toBeFalse();

    $secondRole = Role::create(['name' => 'editor']);
    $user->assignRole($secondRole);

    expect($secondRole->tenant_id)->toBe($secondTenant->getKey())
        ->and($user->hasRole('editor'))->toBeTrue();
});
