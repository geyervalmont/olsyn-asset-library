<?php

use App\Models\DeviceLink;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'Harrison']);
});

test('a client links to an account with a device code and collects its token once', function () {
    $started = $this->postJson('/api/v1/link', ['client' => 'revit', 'machine' => 'HARRISON-VM', 'app_version' => '2027'])
        ->assertCreated()
        ->assertJsonPath('poll_interval', 2)
        ->json();

    expect($started['code'])->toMatch('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/')
        ->and($started['verify_url'])->toContain('/link?code='.$started['code']);

    $poll = "/api/v1/link/{$started['code']}?secret={$started['secret']}";
    $this->getJson($poll)->assertOk()->assertJsonPath('status', 'pending');

    $this->actingAs($this->user)->get('/link?code='.$started['code'])->assertOk()->assertSee('Revit on HARRISON-VM');

    Livewire::actingAs($this->user)
        ->test('pages::link', ['code' => strtolower(str_replace('-', '', $started['code']))])
        ->call('claim')
        ->assertHasNoErrors()
        ->assertSet('linked', true);

    $claimed = $this->getJson($poll)->assertOk()->assertJsonPath('status', 'claimed')->assertJsonPath('user.name', 'Harrison')->json();
    expect($claimed['realtime'])->toHaveKeys(['scheme', 'host', 'port', 'key', 'auth_endpoint'])
        ->and($claimed['realtime']['auth_endpoint'])->toEndWith('/broadcasting/auth');

    $this->getJson($poll)->assertOk()->assertJsonPath('status', 'delivered');
    expect(DeviceLink::sole()->token_plain)->toBeNull();

    $this->app['auth']->forgetGuards();
    $this->withToken($claimed['token'])->getJson('/api/v1/me')->assertOk()->assertJsonPath('email', $this->user->email);
    expect($this->user->tokens()->where('name', 'Revit on HARRISON-VM')->exists())->toBeTrue();

    $this->actingAs($this->user)->get('/settings/api-tokens')->assertOk()->assertSee('Revit on HARRISON-VM');
});

test('a wrong secret is not found, an expired code is gone, and a used code cannot be claimed again', function () {
    $started = $this->postJson('/api/v1/link', ['client' => 'revit', 'machine' => 'VM'])->assertCreated()->json();

    $this->getJson("/api/v1/link/{$started['code']}?secret=nope")->assertNotFound();
    $this->getJson("/api/v1/link/{$started['code']}")->assertUnprocessable();

    Livewire::actingAs($this->user)->test('pages::link', ['code' => 'ZZZZ-ZZZZ'])->call('claim')->assertHasErrors('code');

    Livewire::actingAs($this->user)->test('pages::link', ['code' => $started['code']])->call('claim')->assertSet('linked', true);
    Livewire::actingAs(User::factory()->create())->test('pages::link', ['code' => $started['code']])->call('claim')->assertHasErrors('code');

    $stale = $this->postJson('/api/v1/link', ['client' => 'revit'])->assertCreated()->json();
    $this->travel(11)->minutes();
    $this->getJson("/api/v1/link/{$stale['code']}?secret={$stale['secret']}")->assertStatus(410);
    Livewire::actingAs($this->user)->test('pages::link', ['code' => $stale['code']])->call('claim')->assertHasErrors('code');
});
