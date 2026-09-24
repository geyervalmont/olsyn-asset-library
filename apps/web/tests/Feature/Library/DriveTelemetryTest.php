<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Enums\Role;
use App\Models\DriveConnection;
use App\Models\DriveTelemetryEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    app(SyncRolesAndPermissions::class)->handle();
    $this->tenant = Tenant::factory()->create();
    $this->tenant->makeCurrent();
    $this->user = User::factory()->withTenant($this->tenant, Role::Viewer)->create();
    $this->token = $this->user->createToken('Drive', ['drive:read']);
    $this->payload = ['device_id' => (string) Str::uuid(), 'events' => [[
        'event_id' => (string) Str::uuid(), 'kind' => 'error', 'stage' => 'Mount', 'code' => 'driver_mount',
        'version' => '0.1.3.0', 'occurred_at' => now()->subHour()->toIso8601String(),
        'details' => ['os_version' => '10.0.26200.0', 'hresult' => '0x80070057', 'driver_status' => -5, 'elapsed_ms' => 230],
    ]]];
});

test('drive reports require a valid scoped token and persist a deduplicated safe history', function () {
    Log::spy();
    $this->postJson('/api/v1/drive/telemetry', $this->payload)->assertUnauthorized();
    $this->withToken($this->token->plainTextToken)->postJson('/api/v1/drive/telemetry', $this->payload)->assertOk()
        ->assertJsonPath('data.accepted.0', $this->payload['events'][0]['event_id'])->assertHeader('Cache-Control', 'no-store, private');
    $event = DriveTelemetryEvent::sole();
    expect($event->user_id)->toBe($this->user->id)->and($event->code)->toBe('driver_mount')
        ->and($event->occurred_at->lt($event->received_at))->toBeTrue();
    $changed = $this->payload;
    $changed['events'][0]['code'] = 'network';
    $this->postJson('/api/v1/drive/telemetry', $changed)->assertOk();
    expect(DriveTelemetryEvent::count())->toBe(1)->and($event->fresh()->code)->toBe('driver_mount');
    Log::shouldHaveReceived('warning')->with('drive.client_error', Mockery::type('array'))->once();
    $this->token->accessToken->delete();
    // Discard the guard's cached identity to model a separate request after revocation.
    auth()->forgetGuards();
    $this->postJson('/api/v1/drive/telemetry', $this->payload)->assertUnauthorized();
});

test('telemetry rejects arbitrary text, large batches and malformed metadata without writing partial batches', function () {
    $this->withToken($this->token->plainTextToken);
    foreach ([
        ['user_id' => 123], ['token' => 'secret'],
        ['events' => [array_replace($this->payload['events'][0], ['message' => 'private path'])]],
        ['events' => [array_replace($this->payload['events'][0], ['code' => 'arbitrary'])]],
        ['events' => [array_replace($this->payload['events'][0], ['code' => null])]],
        ['events' => [array_replace($this->payload['events'][0], ['version' => 'private@example.test'])]],
        ['events' => [array_replace($this->payload['events'][0], ['details' => ['os_version' => '10.0.1', 'path' => 'C:\\private']])]],
        ['events' => array_fill(0, 21, $this->payload['events'][0])],
    ] as $override) {
        $this->postJson('/api/v1/drive/telemetry', array_replace($this->payload, $override))->assertUnprocessable();
    }
    $invalid = $this->payload['events'][0];
    $invalid['event_id'] = (string) Str::uuid();
    $invalid['kind'] = 'ready';
    $this->postJson('/api/v1/drive/telemetry', array_replace($this->payload, ['events' => [$this->payload['events'][0], $invalid]]))->assertUnprocessable();
    $this->postJson('/api/v1/drive/telemetry', ['device_id' => str_repeat('a', 33000)])->assertStatus(413);
    expect(DriveTelemetryEvent::count())->toBe(0);
});

test('drive health shows personal history and devices while super-admins can inspect all accounts', function () {
    $this->withToken($this->token->plainTextToken)->postJson('/api/v1/drive/telemetry', $this->payload)->assertOk();
    $otherTenant = Tenant::factory()->create();
    $other = User::factory()->withTenant($otherTenant, Role::Viewer)->create();
    $private = DriveTelemetryEvent::create(array_replace($this->payload['events'][0], ['event_id' => (string) Str::uuid(), 'user_id' => $other->id, 'device_id' => (string) Str::uuid(), 'received_at' => now()]));
    DriveConnection::create(['user_id' => $other->id, 'device_id' => $private->device_id, 'machine' => 'PRIVATE-DEVICE', 'state' => 'error', 'last_seen_at' => now()]);
    Livewire::actingAs($this->user)->test('pages::drive-health')->assertSee($this->payload['events'][0]['event_id'])
        ->assertDontSee($private->event_id)->assertDontSee('PRIVATE-DEVICE')
        ->set('device', $private->device_id)->assertDontSee($private->event_id)->assertDontSee('PRIVATE-DEVICE');
    $this->user->forceFill(['is_super_admin' => true])->save();
    Livewire::actingAs($this->user)->test('pages::drive-health')->assertSee($private->event_id)->assertSee('PRIVATE-DEVICE');
    $this->user->forceFill(['is_super_admin' => false])->save();
    $this->actingAs($this->user)->get('/connect/health')->assertOk()->assertDontSee($private->event_id);
});

test('ready reports are distinct from errors and old telemetry is pruned by receipt time', function () {
    $this->payload['events'][0]['kind'] = 'ready';
    $this->payload['events'][0]['stage'] = 'Ready';
    $this->payload['events'][0]['code'] = null;
    $this->withToken($this->token->plainTextToken)->postJson('/api/v1/drive/telemetry', $this->payload)->assertOk();
    $old = DriveTelemetryEvent::sole();
    $old->update(['received_at' => now()->subDays(31)]);
    $this->payload['events'][0]['event_id'] = (string) Str::uuid();
    $this->postJson('/api/v1/drive/telemetry', $this->payload)->assertOk();
    $this->artisan('opal:drive:prune-telemetry')->assertSuccessful();
    expect(DriveTelemetryEvent::count())->toBe(1)->and(DriveTelemetryEvent::sole()->event_id)->toBe($this->payload['events'][0]['event_id']);
});

test('telemetry cannot be written with an unrelated token ability', function () {
    $other = $this->user->createToken('Unrelated', ['materials:read']);
    $this->withToken($other->plainTextToken)->postJson('/api/v1/drive/telemetry', $this->payload)->assertForbidden();
});
