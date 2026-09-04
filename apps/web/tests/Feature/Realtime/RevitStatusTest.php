<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Enums\Role;
use App\Models\ClientSession;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\LibrarySeeder;

beforeEach(function () {
    $this->seed(LibrarySeeder::class);
    app(SyncRolesAndPermissions::class)->handle();
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();
    $this->viewer = User::factory()->withTenant($tenant, Role::Viewer)->create();
});

test('the shell says when no Revit is connected', function () {
    $this->actingAs($this->viewer)
        ->get(route('materials.index'))
        ->assertOk()
        ->assertSee('data-test="revit-status"', false)
        ->assertSee('data-state="off"', false)
        ->assertSee('No Revit');
});

test('the shell names the machine of a live Revit, and ignores a stale one', function () {
    ClientSession::create([
        'user_id' => $this->viewer->id, 'platform' => 'revit', 'machine' => 'HARRISON-VM',
        'app_version' => '2027', 'document' => 'Tower A.rvt', 'last_seen_at' => now(),
    ]);
    ClientSession::create([
        'user_id' => $this->viewer->id, 'platform' => 'revit', 'machine' => 'OLD-BOX',
        'last_seen_at' => now()->subMinutes(10),
    ]);

    $this->actingAs($this->viewer)
        ->get(route('materials.index'))
        ->assertOk()
        ->assertSee('data-state="live"', false)
        ->assertSee('HARRISON-VM')
        ->assertDontSee('OLD-BOX');
});

test('another person\'s Revit is not this person\'s business', function () {
    $other = User::factory()->create();
    ClientSession::create(['user_id' => $other->id, 'platform' => 'revit', 'machine' => 'THEIR-VM', 'last_seen_at' => now()]);

    $this->actingAs($this->viewer)
        ->get(route('materials.index'))
        ->assertOk()
        ->assertSee('data-state="off"', false)
        ->assertDontSee('THEIR-VM');
});
