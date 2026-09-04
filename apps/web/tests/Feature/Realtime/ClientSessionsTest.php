<?php

use App\Actions\Clients\IssueClientCommand;
use App\Enums\CommandType;
use App\Events\Realtime\CommandAcked;
use App\Events\Realtime\CommandCompleted;
use App\Events\Realtime\CommandQueued;
use App\Events\Realtime\SessionUpdated;
use App\Models\ClientSession;
use App\Models\User;
use Database\Seeders\LibrarySeeder;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(LibrarySeeder::class);
    $this->user = User::factory()->create();
    $this->other = User::factory()->create();
});

test('a client announces a session, heartbeats it, and ends it', function () {
    Event::fake([SessionUpdated::class]);
    Sanctum::actingAs($this->user);

    $created = $this->postJson('/api/v1/sessions', ['platform' => 'revit', 'machine' => 'HARRISON-VM', 'app_version' => '2027', 'document' => 'Tower A.rvt'])
        ->assertCreated()
        ->json('data');

    expect($created['channel'])->toBe('revit-session.'.ClientSession::sole()->id);

    Event::assertDispatched(SessionUpdated::class, fn (SessionUpdated $event) => $event->broadcastOn()[0]->name === 'private-user.'.$this->user->id
        && $event->broadcastWith()['live'] === true
        && $event->broadcastWith()['label'] === 'Revit 2027 · HARRISON-VM · Tower A.rvt');

    $this->getJson('/api/v1/sessions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.document', 'Tower A.rvt');
    $this->getJson('/api/v1/realtime')->assertOk()->assertJsonPath('data.port', (int) config('opal.realtime.port'));

    $this->travel(2)->minutes();
    $this->getJson('/api/v1/sessions')->assertOk()->assertJsonCount(0, 'data');

    $this->postJson("/api/v1/sessions/{$created['id']}/heartbeat", ['document' => 'Tower B.rvt'])->assertOk();
    $this->getJson('/api/v1/sessions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.document', 'Tower B.rvt');

    $this->deleteJson("/api/v1/sessions/{$created['id']}")->assertNoContent();
    $this->getJson('/api/v1/sessions')->assertOk()->assertJsonCount(0, 'data');
    expect(ClientSession::sole()->ended_at)->not->toBeNull();

    $this->postJson('/api/v1/sessions', ['platform' => 'nope', 'machine' => 'x'])->assertUnprocessable();
});

test('sessions belong to their user', function () {
    $session = ClientSession::create(['user_id' => $this->user->id, 'platform' => 'revit', 'machine' => 'VM', 'last_seen_at' => now()]);
    Sanctum::actingAs($this->other);

    $this->postJson("/api/v1/sessions/{$session->id}/heartbeat")->assertNotFound();
    $this->deleteJson("/api/v1/sessions/{$session->id}")->assertNotFound();
    $this->getJson("/api/v1/sessions/{$session->id}/commands")->assertNotFound();
});

test('commands are queued, acknowledged, and completed with broadcasts on the way', function () {
    Event::fake([CommandQueued::class, CommandAcked::class, CommandCompleted::class]);
    $session = ClientSession::create(['user_id' => $this->user->id, 'platform' => 'revit', 'machine' => 'VM', 'app_version' => '2027', 'last_seen_at' => now()]);

    $command = app(IssueClientCommand::class)->handle($session, $this->user, CommandType::Apply, ['variant' => 'CPT-TARKETT-ACADEMIX-ASHEN']);

    Event::assertDispatched(CommandQueued::class, fn (CommandQueued $event) => $event->broadcastAs() === 'command.queued'
        && $event->broadcastOn()[0]->name === 'private-revit-session.'.$session->id
        && $event->broadcastWith()['payload']['variant'] === 'CPT-TARKETT-ACADEMIX-ASHEN');

    Sanctum::actingAs($this->user);
    $this->getJson("/api/v1/sessions/{$session->id}/commands")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'apply');

    $this->postJson("/api/v1/commands/{$command->id}/ack")->assertOk()->assertJsonPath('data.status', 'acked');
    Event::assertDispatched(CommandAcked::class, fn (CommandAcked $event) => $event->broadcastAs() === 'command.acked'
        && collect($event->broadcastOn())->map->name->all() === ['private-revit-session.'.$session->id, 'private-user.'.$this->user->id]);
    $this->getJson("/api/v1/sessions/{$session->id}/commands")->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/sessions/{$session->id}/commands?status=acked")->assertOk()->assertJsonCount(1, 'data');

    $this->postJson("/api/v1/commands/{$command->id}/result", ['status' => 'done', 'result' => ['material' => 'Carpet - Academix'], 'message' => 'Applied to 1 material'])
        ->assertOk()
        ->assertJsonPath('data.status', 'done')
        ->assertJsonPath('data.result.material', 'Carpet - Academix');
    Event::assertDispatched(CommandCompleted::class, fn (CommandCompleted $event) => $event->broadcastAs() === 'command.completed'
        && $event->broadcastWith()['message'] === 'Applied to 1 material'
        && $event->broadcastWith()['session_id'] === $session->id);

    $this->postJson("/api/v1/commands/{$command->id}/result", ['status' => 'maybe'])->assertUnprocessable();

    $this->app['auth']->forgetGuards();
    Sanctum::actingAs($this->other);
    $this->postJson("/api/v1/commands/{$command->id}/ack")->assertNotFound();
    $this->postJson("/api/v1/commands/{$command->id}/result", ['status' => 'failed'])->assertNotFound();
});

test('private channels are authorised for their owner over a session or a bearer token', function () {
    // The null driver in tests authorises nothing; sign with a Reverb driver
    // carrying the channels registered at boot.
    config(['broadcasting.connections.reverb' => ['driver' => 'reverb', 'key' => 'key', 'secret' => 'secret', 'app_id' => '1', 'options' => ['host' => 'reverb', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false]]]);
    $reverb = Broadcast::driver('reverb');
    foreach (Broadcast::driver()->getChannels() as $channel => $callback) {
        $reverb->channel($channel, $callback);
    }
    Broadcast::setDefaultDriver('reverb');
    $session = ClientSession::create(['user_id' => $this->user->id, 'platform' => 'revit', 'machine' => 'VM', 'last_seen_at' => now()]);

    $this->actingAs($this->user)->post('/broadcasting/auth', ['channel_name' => 'private-user.'.$this->user->id, 'socket_id' => '1234.5678'])->assertOk()->assertJsonStructure(['auth']);
    $this->actingAs($this->user)->post('/broadcasting/auth', ['channel_name' => 'private-revit-session.'.$session->id, 'socket_id' => '1234.5678'])->assertOk();
    $this->actingAs($this->user)->post('/broadcasting/auth', ['channel_name' => 'private-user.'.$this->other->id, 'socket_id' => '1234.5678'])->assertForbidden();

    $this->app['auth']->forgetGuards();
    $token = $this->other->createToken('revit')->plainTextToken;
    $this->withToken($token)->postJson('/broadcasting/auth', ['channel_name' => 'private-revit-session.'.$session->id, 'socket_id' => '1234.5678'])->assertForbidden();
    $this->withToken($token)->postJson('/broadcasting/auth', ['channel_name' => 'private-user.'.$this->other->id, 'socket_id' => '1234.5678'])->assertOk();

    $this->flushHeaders();
    $this->app['auth']->forgetGuards();
    $this->postJson('/broadcasting/auth', ['channel_name' => 'private-user.'.$this->user->id, 'socket_id' => '1234.5678'])->assertUnauthorized();
});
