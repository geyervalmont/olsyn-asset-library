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
    app(PublishVersion::class)->handle(app(CutVersion::class)->handle($this->material));
    $this->material->refresh();
});

test('an unpublished material shows the apply button disabled, with the reason', function () {
    ClientSession::create(['user_id' => $this->viewer->id, 'platform' => 'revit', 'machine' => 'HARRISON-VM', 'app_version' => '2027', 'last_seen_at' => now()]);
    $draft = Material::factory()->create(['name' => 'Draft', 'category_id' => Category::query()->where('code', 'CPT')->sole(), 'supplier_id' => Supplier::factory()->create()]);
    app(AddVariant::class)->handle($draft, ['colourway' => ['value' => 'Plain']]);

    Livewire::actingAs($this->viewer)
        ->test('pages::materials.show', ['material' => $draft])
        ->assertSee('Publish a version first')
        ->assertSeeHtml('data-test="apply-in-revit-button"')
        ->assertSeeHtml('disabled');
});

test('the material page keeps one apply button, and issues a command for the chosen colourway', function () {
    Event::fake([CommandQueued::class]);

    Livewire::actingAs($this->viewer)
        ->test('pages::materials.show', ['material' => $this->material])
        ->assertSee('No Revit connected')
        ->assertSeeHtml('disabled');

    $session = ClientSession::create(['user_id' => $this->viewer->id, 'platform' => 'revit', 'machine' => 'HARRISON-VM', 'app_version' => '2027', 'document' => 'Tower A.rvt', 'last_seen_at' => now()]);
    ClientSession::create(['user_id' => $this->viewer->id, 'platform' => 'revit', 'machine' => 'OLD', 'last_seen_at' => now()->subMinutes(5)]);

    $page = Livewire::actingAs($this->viewer)
        ->test('pages::materials.show', ['material' => $this->material])
        ->assertSet('revitSessionId', $session->id)
        ->assertSee('Revit 2027 · HARRISON-VM · Tower A.rvt')
        ->assertDontSee('OLD')
        ->call('applyInRevit', $this->ashen->id)
        ->assertSee('Queued');

    $command = ClientCommand::sole();
    expect($command->client_session_id)->toBe($session->id)
        ->and($command->issued_by)->toBe($this->viewer->id)
        ->and($command->payload['variant'])->toBe($this->ashen->code)
        ->and($page->get('revitCommandId'))->toBe($command->id);
    Event::assertDispatched(CommandQueued::class);

    $command->forceFill(['status' => 'done', 'message' => 'Applied to Carpet - Academix'])->save();
    $page->call('revitCommandUpdated')->assertSee('Applied to Carpet - Academix');
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
