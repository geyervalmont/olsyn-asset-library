<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Actions\Tenants\AddTenantMember;
use App\Actions\Tenants\CreateTenant;
use App\Actions\Tenants\RemoveTenantMember;
use App\Actions\Tenants\SwitchCurrentTenant;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    app(SyncRolesAndPermissions::class)->handle();

    Route::middleware(['web', 'auth', 'tenant'])->get('/_test/tenant-only', function () {
        return response()->json(['tenant_id' => Tenant::current()?->getKey()]);
    });
});

afterEach(function () {
    Tenant::forgetCurrent();
});

test('creating a tenant makes its owner an admin and selects it', function () {
    $owner = User::factory()->create();

    $tenant = app(CreateTenant::class)->handle('Acme Architects', $owner);

    expect($tenant->slug)->toBe('acme-architects')
        ->and($owner->fresh()->current_tenant_id)->toBe($tenant->getKey())
        ->and($owner->isMemberOf($tenant))->toBeTrue()
        ->and($owner->roleIn($tenant))->toBe(Role::Admin);

    $tenant->makeCurrent();

    expect($owner->can(Permission::ManageMembers->value))->toBeTrue();

    $second = app(CreateTenant::class)->handle('Acme Architects');

    expect($second->slug)->toBe('acme-architects-2')
        ->and($second->users()->count())->toBe(0);
});

test('a user can hold different roles in different tenants', function () {
    $user = User::factory()->create();
    $studio = Tenant::factory()->create();
    $client = Tenant::factory()->create();

    app(AddTenantMember::class)->handle($studio, $user, Role::Editor);
    app(AddTenantMember::class)->handle($client, $user, Role::Viewer);

    expect($user->roleIn($studio))->toBe(Role::Editor)
        ->and($user->roleIn($client))->toBe(Role::Viewer)
        ->and($user->accessibleTenants()->count())->toBe(2);

    app(AddTenantMember::class)->handle($studio, $user, Role::Admin);

    expect($user->roleIn($studio))->toBe(Role::Admin);

    app(RemoveTenantMember::class)->handle($studio, $user);

    expect($user->fresh()->isMemberOf($studio))->toBeFalse()
        ->and($user->roleIn($client))->toBe(Role::Viewer);
});

test('a user without a workspace is sent to the dashboard by tenant routes', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/_test/tenant-only')
        ->assertRedirect(route('dashboard'));

    $this->actingAs($user)
        ->getJson('/_test/tenant-only')
        ->assertForbidden();
});

test('a stale current tenant is replaced by the first membership', function () {
    $lost = Tenant::factory()->create(['name' => 'Lost']);
    $kept = Tenant::factory()->create(['name' => 'Kept']);
    $user = User::factory()->withTenant($kept)->create();
    $user->forceFill(['current_tenant_id' => $lost->getKey()])->save();

    $this->actingAs($user)
        ->get('/_test/tenant-only')
        ->assertOk()
        ->assertJson(['tenant_id' => $kept->getKey()]);

    expect($user->fresh()->current_tenant_id)->toBe($kept->getKey());
});

test('a super-admin can enter any tenant without membership', function () {
    $tenant = Tenant::factory()->create();
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->post(route('tenants.switch', $tenant))
        ->assertRedirect(route('dashboard'));

    expect($superAdmin->fresh()->current_tenant_id)->toBe($tenant->getKey());

    $this->actingAs($superAdmin)
        ->get('/_test/tenant-only')
        ->assertOk()
        ->assertJson(['tenant_id' => $tenant->getKey()]);
});

test('members can only switch to their own tenants over http', function () {
    $mine = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();
    $user = User::factory()->withTenant($mine)->create();

    $this->actingAs($user)
        ->post(route('tenants.switch', $theirs))
        ->assertForbidden();

    expect($user->fresh()->current_tenant_id)->toBe($mine->getKey());

    $request = Request::create('/tenants/switch', 'POST');

    expect(fn () => app(SwitchCurrentTenant::class)->handle($user, $theirs, $request))
        ->toThrow(AuthorizationException::class);
});
