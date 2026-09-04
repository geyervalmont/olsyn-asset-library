<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Actions\Materials\AddVariant;
use App\Actions\Representations\CreateRepresentation;
use App\Actions\Representations\ReviewRepresentation;
use App\Actions\Versions\CutVersion;
use App\Actions\Versions\PublishVersion;
use App\Enums\ReviewState;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Library\FileStore;
use App\Models\Category;
use App\Models\ClientCommand;
use App\Models\ClientSession;
use App\Models\Material;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\LibrarySeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake(config('opal.files_disk'));
    $this->seed(LibrarySeeder::class);
    app(SyncRolesAndPermissions::class)->handle();
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();
    $this->viewer = User::factory()->withTenant($tenant, Role::Viewer)->create();

    $this->material = Material::factory()->create([
        'name' => 'Academix',
        'category_id' => Category::query()->where('code', 'CPT')->sole(),
        'supplier_id' => Supplier::factory()->create(['name' => 'Tarkett']),
    ]);
    $this->ashen = app(AddVariant::class)->handle($this->material, ['colourway' => ['value' => 'Ashen']]);
    $this->slate = app(AddVariant::class)->handle($this->material, ['colourway' => ['value' => 'Slate']]);

    $image = imagecreatetruecolor(4, 4);
    ob_start();
    imagepng($image);
    $file = app(FileStore::class)->store((string) ob_get_clean(), 'base.png');
    foreach ([$this->ashen, $this->slate] as $variant) {
        app(ReviewRepresentation::class)->handle(
            app(CreateRepresentation::class)->handle($variant, 'pbr', '1k', ['base_color' => $file]),
            ReviewState::Approved,
        );
    }
    app(PublishVersion::class)->handle(app(CutVersion::class)->handle($this->material));
    $this->material->refresh();
});

test('the library opens a material in a quick view and closes it again', function () {
    Livewire::actingAs($this->viewer)
        ->test('pages::materials.index')
        ->assertDontSeeHtml('data-test="material-modal"')
        ->call('openQuick', $this->material->code)
        ->assertSet('quick', $this->material->code)
        ->assertSeeHtml('data-test="material-modal"')
        ->assertSee($this->material->code)
        ->assertSee('Ashen')
        ->assertSee('Full record')
        ->call('closeQuick')
        ->assertSet('quick', '')
        ->assertDontSeeHtml('data-test="material-modal"');
});

test('a material code in the address opens the quick view straight away', function () {
    Livewire::actingAs($this->viewer)
        ->withQueryParams(['material' => strtolower($this->material->code)])
        ->test('pages::materials.index')
        ->assertSeeHtml('data-test="material-modal"')
        ->assertSee('Academix');
});

test('the quick view applies the colourway it is given and reports the command', function () {
    $session = ClientSession::create([
        'user_id' => $this->viewer->id, 'platform' => 'revit', 'machine' => 'HARRISON-VM',
        'app_version' => '2027', 'document' => 'Tower A.rvt', 'last_seen_at' => now(),
    ]);

    $page = Livewire::actingAs($this->viewer)
        ->test('pages::materials.index')
        ->call('openQuick', $this->material->code)
        ->assertSet('revitSessionId', $session->id)
        ->call('applyInRevit', $this->slate->id)
        ->assertSee('Queued');

    $command = ClientCommand::sole();
    expect($command->payload['variant'])->toBe($this->slate->code)
        ->and($command->payload['material'])->toBe($this->material->code)
        ->and($command->client_session_id)->toBe($session->id)
        ->and($page->get('revitCommandId'))->toBe($command->id);

    $command->forceFill(['status' => 'done', 'message' => 'Applied to Carpet'])->save();
    $page->call('revitCommandUpdated')->assertSee('Applied to Carpet');
});

test('the quick view says why it cannot apply, and refuses to', function () {
    // No Revit connected.
    Livewire::actingAs($this->viewer)
        ->test('pages::materials.index')
        ->call('openQuick', $this->material->code)
        ->assertSee('No Revit connected')
        ->call('applyInRevit', $this->ashen->id);
    expect(ClientCommand::count())->toBe(0);

    // Connected, but the material has nothing on a drive.
    ClientSession::create(['user_id' => $this->viewer->id, 'platform' => 'revit', 'machine' => 'HARRISON-VM', 'last_seen_at' => now()]);
    $draft = Material::factory()->create(['category_id' => Category::query()->where('code', 'CPT')->sole(), 'supplier_id' => Supplier::factory()->create()]);
    $plain = app(AddVariant::class)->handle($draft, ['colourway' => ['value' => 'Plain']]);

    Livewire::actingAs($this->viewer)
        ->test('pages::materials.index')
        ->call('openQuick', $draft->code)
        ->assertSee('Publish a version first')
        ->call('applyInRevit', $plain->id);
    expect(ClientCommand::count())->toBe(0);
});

test('the quick view opens on the colourway the card was showing', function () {
    Livewire::actingAs($this->viewer)
        ->test('pages::materials.index')
        ->call('openQuick', $this->material->code, $this->slate->id)
        ->assertSet('quickVariantId', $this->slate->id)
        ->assertSeeHtml($this->slate->code);
});

test('a material out of reach never opens', function () {
    $secret = Material::factory()->create(['visibility' => Visibility::Restricted]);

    Livewire::actingAs($this->viewer)
        ->test('pages::materials.index')
        ->call('openQuick', $secret->code)
        ->assertDontSeeHtml('data-test="material-modal"');
});
