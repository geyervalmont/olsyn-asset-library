<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    app(SyncRolesAndPermissions::class)->handle();
});

afterEach(function () {
    Tenant::forgetCurrent();
});

test('a super-admin passes every ability in every tenant', function () {
    $tenant = Tenant::factory()->create();
    $superAdmin = User::factory()->superAdmin()->create();

    Gate::define('anything-at-all', fn (User $user): bool => false);

    expect($superAdmin->isSuperAdmin())->toBeTrue()
        ->and($superAdmin->can('anything-at-all'))->toBeTrue()
        ->and($superAdmin->can(Permission::PublishMaterials->value))->toBeTrue()
        ->and($superAdmin->canAccessTenant($tenant))->toBeTrue()
        ->and($superAdmin->accessibleTenants()->pluck('id')->all())->toBe([$tenant->getKey()]);

    $tenant->makeCurrent();

    expect($superAdmin->can(Permission::ManageTenant->value))->toBeTrue()
        ->and($superAdmin->isMemberOf($tenant))->toBeFalse();
});

test('a regular member only holds the permissions of their tenant role', function () {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();
    $viewer = User::factory()->withTenant($tenant, Role::Viewer)->create();

    Gate::define('anything-at-all', fn (User $user): bool => false);

    expect($viewer->can('anything-at-all'))->toBeFalse()
        ->and($viewer->accessibleTenants()->pluck('id')->all())->toBe([$tenant->getKey()])
        ->and($viewer->canAccessTenant($otherTenant))->toBeFalse();

    $tenant->makeCurrent();

    expect($viewer->can(Permission::ViewMaterials->value))->toBeTrue()
        ->and($viewer->can(Permission::PublishMaterials->value))->toBeFalse()
        ->and($viewer->roleIn($tenant))->toBe(Role::Viewer);

    $otherTenant->makeCurrent();
    $viewer->unsetRelation('roles')->unsetRelation('permissions');

    expect($viewer->can(Permission::ViewMaterials->value))->toBeFalse()
        ->and($viewer->roleIn($otherTenant))->toBeNull();
});

test('role definitions are global and sync idempotently', function () {
    app(SyncRolesAndPermissions::class)->handle();

    $roles = Spatie\Permission\Models\Role::query()->get();

    expect($roles)->toHaveCount(count(Role::cases()))
        ->and($roles->pluck('tenant_id')->unique()->all())->toBe([null])
        ->and(Spatie\Permission\Models\Permission::query()->count())->toBe(count(Permission::cases()));

    $admin = $roles->firstWhere('name', Role::Admin->value);
    $viewer = $roles->firstWhere('name', Role::Viewer->value);

    expect($admin->permissions->pluck('name')->sort()->values()->all())
        ->toBe(collect(Permission::cases())->map->value->sort()->values()->all())
        ->and($viewer->permissions->pluck('name')->all())->toBe([Permission::ViewMaterials->value]);
});

test('super-admin access is granted and revoked from the console', function () {
    $user = User::factory()->create();

    $this->artisan('olsyn:super-admin', ['email' => $user->email])
        ->assertSuccessful();

    expect($user->fresh()->isSuperAdmin())->toBeTrue();

    $this->artisan('olsyn:super-admin', ['email' => $user->email, '--revoke' => true])
        ->assertSuccessful();

    expect($user->fresh()->isSuperAdmin())->toBeFalse();

    $this->artisan('olsyn:super-admin', ['email' => 'nobody@example.com'])
        ->assertFailed();
});

test('registration never produces a super-admin or a membership', function () {
    $this->post(route('register.store'), [
        'name' => 'Plain User',
        'email' => 'plain@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    $user = User::query()->where('email', 'plain@example.com')->sole();

    expect($user->isSuperAdmin())->toBeFalse()
        ->and($user->tenants()->count())->toBe(0)
        ->and($user->current_tenant_id)->toBeNull()
        ->and($user->hasVerifiedEmail())->toBeFalse();
});
