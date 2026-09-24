<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Actions\Materials\AddVariant;
use App\Actions\Representations\CreateRepresentation;
use App\Actions\Representations\ReviewRepresentation;
use App\Actions\Versions\CutVersion;
use App\Actions\Versions\PublishVersion;
use App\Enums\ReviewState;
use App\Enums\Role;
use App\Events\Realtime\CommandQueued;
use App\Library\FileStore;
use App\Models\Category;
use App\Models\ClientCommand;
use App\Models\ClientSession;
use App\Models\Material;
use App\Models\Platform;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\LibrarySeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake(config('opal.files_disk'));
    $this->seed(LibrarySeeder::class);
    app(SyncRolesAndPermissions::class)->handle();
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();
    $this->viewer = User::factory()->withTenant($tenant, Role::Viewer)->create();
    $carpet = Category::query()->where('code', 'CPT')->sole();
    $this->material = Material::factory()->create(['name' => 'Academix', 'category_id' => $carpet, 'supplier_id' => Supplier::factory()->create(['name' => 'Tarkett'])]);
    $this->ashen = app(AddVariant::class)->handle($this->material, ['colourway' => ['value' => 'Ashen']]);
    // Apply in Revit needs files on the drive, so publish an approved set.
    $image = imagecreatetruecolor(4, 4);
    ob_start();
    imagepng($image);
    $file = app(FileStore::class)->store((string) ob_get_clean(), 'base.png');
    $representation = app(CreateRepresentation::class)->handle($this->ashen, 'pbr', '1k', ['base_color' => $file]);
    app(ReviewRepresentation::class)->handle($representation, ReviewState::Approved);
    publishablePackage($this->ashen, '1k');
    app(PublishVersion::class)->handle(app(CutVersion::class)->handle($this->material));
    $this->material->refresh();
});

test('an unpublished material explains why apply is blocked while keeping the action responsive', function () {
    ClientSession::create(['user_id' => $this->viewer->id, 'platform' => 'revit', 'machine' => 'HARRISON-VM', 'app_version' => '2027', 'last_seen_at' => now()]);
    $draft = Material::factory()->create(['name' => 'Draft', 'category_id' => Category::query()->where('code', 'CPT')->sole(), 'supplier_id' => Supplier::factory()->create()]);
    app(AddVariant::class)->handle($draft, ['colourway' => ['value' => 'Plain']]);

    Livewire::actingAs($this->viewer)
        ->test('pages::materials.show', ['material' => $draft])
        ->assertSee('Publish a version first')
        ->assertSeeHtml('data-test="apply-to-consumer-button"')
        ->assertSeeHtml('aria-disabled="true"');
});

test('the material page keeps one apply button, and issues a command for the chosen colourway', function () {
    Event::fake([CommandQueued::class]);

    Livewire::actingAs($this->viewer)
        ->test('pages::materials.show', ['material' => $this->material])
        ->assertSee('No compatible app connected')
        ->assertSeeHtml('aria-disabled="true"');

    $session = ClientSession::create(['user_id' => $this->viewer->id, 'platform' => 'revit', 'machine' => 'HARRISON-VM', 'app_version' => '2027', 'document' => 'Tower A.rvt', 'last_seen_at' => now()]);
    ClientSession::create(['user_id' => $this->viewer->id, 'platform' => 'revit', 'machine' => 'STALE-REVIT-WORKSTATION', 'last_seen_at' => now()->subMinutes(5)]);

    $page = Livewire::actingAs($this->viewer)
        ->test('pages::materials.show', ['material' => $this->material])
        ->assertSet('consumerSessionId', $session->id)
        ->assertSee('Revit 2027 · HARRISON-VM · Tower A.rvt')
        ->assertDontSee('STALE-REVIT-WORKSTATION')
        ->call('applyToConsumer', $this->ashen->id)
        ->assertSee('Queued');

    $command = ClientCommand::sole();
    expect($command->client_session_id)->toBe($session->id)
        ->and($command->issued_by)->toBe($this->viewer->id)
        ->and($command->payload['variant'])->toBe($this->ashen->code)
        ->and($page->get('consumerCommandId'))->toBe($command->id);
    Event::assertDispatched(CommandQueued::class);

    $command->forceFill(['status' => 'done', 'message' => 'Applied to Carpet - Academix'])->save();
    $page->call('consumerCommandUpdated')->assertSee('Applied to Carpet - Academix');
});

test('the sessions settings page lists live sessions and can end one', function () {
    $session = ClientSession::create(['user_id' => $this->viewer->id, 'platform' => 'revit', 'machine' => 'HARRISON-VM', 'last_seen_at' => now()]);

    Livewire::actingAs($this->viewer)
        ->test('pages::settings.sessions')
        ->assertSee('HARRISON-VM')
        ->call('end', $session->id)
        ->assertDontSee('HARRISON-VM');

    expect($session->fresh()->ended_at)->not->toBeNull();
});

test('Omniverse alone is an apply target and receives the published UUID and version', function () {
    $session = ClientSession::create(['user_id' => $this->viewer->id, 'platform' => 'omniverse', 'machine' => 'LINUX', 'capabilities' => ['material.apply', 'draft.apply'], 'last_seen_at' => now()]);
    Livewire::actingAs($this->viewer)->test('pages::materials.show', ['material' => $this->material])
        ->assertSee('Apply in Omniverse')->assertSet('consumerSessionId', $session->id)
        ->call('applyToConsumer', $this->ashen->id);
    $command = ClientCommand::sole();
    expect($command->client_session_id)->toBe($session->id)
        ->and($command->payload['variant_uuid'])->toBe($this->ashen->uuid)
        ->and($command->payload['material_version'])->toBe($this->material->currentVersion->number);
});

test('multiple consumers require a choice and only the selected consumer receives the material', function () {
    ClientSession::create(['user_id' => $this->viewer->id, 'platform' => 'revit', 'machine' => 'REVIT-PC', 'last_seen_at' => now()]);
    $omni = ClientSession::create(['user_id' => $this->viewer->id, 'platform' => 'omniverse', 'machine' => 'KIT-PC', 'capabilities' => ['material.apply'], 'last_seen_at' => now()]);
    $page = Livewire::actingAs($this->viewer)->test('pages::materials.show', ['material' => $this->material])
        ->assertSet('consumerSessionId', null)->assertSee('Choose an application')
        ->call('applyToConsumer', $this->ashen->id);
    expect(ClientCommand::count())->toBe(0);
    $page->set('consumerSessionId', $omni->id)->assertSee('Apply in Omniverse')->call('applyToConsumer', $this->ashen->id);
    expect(ClientCommand::sole()->client_session_id)->toBe($omni->id);
});

test('an old Omniverse session is connected but is not offered unsupported apply commands', function () {
    ClientSession::create(['user_id' => $this->viewer->id, 'platform' => 'omniverse', 'machine' => 'OLD-KIT', 'last_seen_at' => now()]);
    Livewire::actingAs($this->viewer)->test('pages::materials.show', ['material' => $this->material])
        ->assertSee('No compatible app connected')->call('applyToConsumer', $this->ashen->id);
    expect(ClientCommand::count())->toBe(0);
});

test('a disconnected selection is never silently redirected to another app', function () {
    $revit = ClientSession::create(['user_id' => $this->viewer->id, 'platform' => 'revit', 'machine' => 'REVIT-PC', 'last_seen_at' => now()]);
    $page = Livewire::actingAs($this->viewer)->test('pages::materials.show', ['material' => $this->material]);
    $revit->update(['ended_at' => now()]);
    ClientSession::create(['user_id' => $this->viewer->id, 'platform' => 'omniverse', 'machine' => 'KIT-PC', 'capabilities' => ['material.apply'], 'last_seen_at' => now()]);
    $page->call('applyToConsumer', $this->ashen->id)->assertSee('Choose a connected application');
    expect(ClientCommand::count())->toBe(0);
});

test('future consumers opt into apply without platform-specific website code', function () {
    Platform::firstOrCreate(['slug' => 'future-editor'], ['name' => 'Future Editor']);
    $session = ClientSession::create(['user_id' => $this->viewer->id, 'platform' => 'future-editor', 'machine' => 'DESIGN-PC', 'capabilities' => ['material.apply'], 'last_seen_at' => now()]);
    Livewire::actingAs($this->viewer)->test('pages::materials.index')
        ->call('openQuick', $this->material->code)->assertSee('Apply in Future Editor')
        ->call('applyToConsumer', $this->ashen->id);
    expect(ClientCommand::sole()->client_session_id)->toBe($session->id);
});

test('another user’s session cannot be selected by changing the public session id', function () {
    $other = User::factory()->create();
    $session = ClientSession::create(['user_id' => $other->id, 'platform' => 'revit', 'machine' => 'PRIVATE-PC', 'last_seen_at' => now()]);
    Livewire::actingAs($this->viewer)->test('pages::materials.show', ['material' => $this->material])
        ->set('consumerSessionId', $session->id)->call('applyToConsumer', $this->ashen->id);
    expect(ClientCommand::count())->toBe(0);
});
