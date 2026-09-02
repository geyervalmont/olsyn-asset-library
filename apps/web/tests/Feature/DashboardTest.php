<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('the dashboard explains when a user has no workspace', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-test="no-workspace"', false)
        ->assertDontSee('data-test="super-admin-badge"', false)
        ->assertDontSee('data-test="all-workspaces"', false);
});

test('the dashboard shows the current workspace and role', function () {
    app(SyncRolesAndPermissions::class)->handle();

    $tenant = Tenant::factory()->create(['name' => 'Olsyn Studio']);
    $user = User::factory()->withTenant($tenant, Role::Editor)->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Olsyn Studio')
        ->assertSee('Editor')
        ->assertDontSee('data-test="all-workspaces"', false);
});

test('the dashboard lists every workspace for a super-admin', function () {
    Tenant::factory()->create(['name' => 'Hidden Corp']);
    $superAdmin = User::factory()->superAdmin()->create();

    $this->actingAs($superAdmin)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-test="super-admin-badge"', false)
        ->assertSee('data-test="all-workspaces"', false)
        ->assertSee('Hidden Corp');
});
