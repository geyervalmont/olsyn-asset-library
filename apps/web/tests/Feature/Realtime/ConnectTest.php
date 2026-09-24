<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Enums\Role;
use App\Models\ClientSession;
use App\Models\DriveConnection;
use App\Models\DriveIntakeSession;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ClientReleaseCatalog;
use Database\Seeders\LibrarySeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LibrarySeeder::class);
    app(SyncRolesAndPermissions::class)->handle();
    $this->tenant = Tenant::factory()->create();
    $this->tenant->makeCurrent();
    $this->editor = User::factory()->withTenant($this->tenant, Role::Editor)->create();
    $this->viewer = User::factory()->withTenant($this->tenant, Role::Viewer)->create();
    $this->mock(ClientReleaseCatalog::class)->shouldReceive('latest')->andReturn([
        'version' => '1.2.3', 'installer' => ['url' => 'https://example.test/installer.exe'],
    ]);
});

test('connect guides setup and shows only the signed-in persons devices', function () {
    $mine = ClientSession::create(['user_id' => $this->editor->id, 'platform' => 'revit', 'machine' => 'MY-WORKSTATION', 'last_seen_at' => now(), 'drive_status' => 'missing']);
    $other = ClientSession::create(['user_id' => $this->viewer->id, 'platform' => 'revit', 'machine' => 'PRIVATE-WORKSTATION', 'last_seen_at' => now()]);
    $this->actingAs($this->editor)->get('/connect')->assertOk()->assertSee('Download for Windows')->assertSee('MY-WORKSTATION')
        ->assertSee('Using HTTPS downloads')->assertDontSee('PRIVATE-WORKSTATION')->assertSee('not included in the Revit installer yet');
    expect(fn () => Livewire::actingAs($this->editor)->test('pages::connect')->call('endSession', $other->id))->toThrow(ModelNotFoundException::class);
    expect($other->fresh()->ended_at)->toBeNull();
    Livewire::actingAs($this->editor)->test('pages::connect')->call('endSession', $mine->id)->assertHasNoErrors();
    expect($mine->fresh()->ended_at)->not->toBeNull();
    $this->actingAs($this->editor)->get('/connect/it')->assertOk();
});

test('connect lets contributors prepare and close only their own intake folders', function () {
    Livewire::actingAs($this->editor)->test('pages::connect')->set('batchName', 'Designer textures')->call('createBatch')->assertHasNoErrors()->assertSee('Designer textures');
    $session = DriveIntakeSession::sole();
    expect($session->user_id)->toBe($this->editor->id)->and($session->acceptsUploads())->toBeTrue();
    Livewire::actingAs($this->viewer)->test('pages::connect')->assertDontSee('Prepare upload folder')->set('batchName', 'Denied')->call('createBatch')->assertForbidden();
    $otherEditor = User::factory()->withTenant($this->tenant, Role::Editor)->create();
    expect(fn () => Livewire::actingAs($otherEditor)->test('pages::connect')->call('cancelBatch', $session->uuid))->toThrow(ModelNotFoundException::class);
    Livewire::actingAs($this->editor)->test('pages::connect')->call('cancelBatch', $session->uuid)->assertHasNoErrors();
    expect($session->fresh()->status)->toBe('cancelled');
});

test('disconnect revokes drive credentials and stale or expired credentials are never live', function () {
    $token = $this->editor->createToken('Drive', ['drive:read']);
    $connection = DriveConnection::create(['user_id' => $this->editor->id, 'token_id' => $token->accessToken->id,
        'device_id' => (string) Str::uuid(), 'machine' => 'MY-DRIVE', 'state' => 'mounted', 'mount_path' => 'O:\\', 'last_seen_at' => now()]);
    expect($connection->isLive())->toBeTrue();
    expect(fn () => Livewire::actingAs($this->viewer)->test('pages::connect')->call('disconnectDrive', $connection->id))->toThrow(ModelNotFoundException::class);
    $token->accessToken->forceFill(['expires_at' => now()->subMinute()])->save();
    expect($connection->isLive())->toBeFalse();
    Livewire::actingAs($this->editor)->test('pages::connect')->call('disconnectDrive', $connection->id)->assertHasNoErrors();
    expect($this->editor->tokens()->exists())->toBeFalse()->and($connection->fresh()->state)->toBe('offline');
});

test('setup stays useful when the release service is unavailable', function () {
    $this->mock(ClientReleaseCatalog::class)->shouldReceive('latest')->andThrow(new RuntimeException('Unavailable'));
    $this->actingAs($this->viewer)->get('/connect')->assertOk()->assertSee('temporarily unavailable')->assertSee('Enter a connection code')->assertDontSee('connect-download');
});
