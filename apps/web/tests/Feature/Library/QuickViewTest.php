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
use App\Livewire\Materials\QuickView;
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
        publishablePackage($variant, '1k');
    }
    app(PublishVersion::class)->handle(app(CutVersion::class)->handle($this->material));
    $this->material->refresh();
});

test('the library opens a material in a quick view and closes it again', function () {
    Livewire::actingAs($this->viewer)
        ->test(QuickView::class)
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
        ->test(QuickView::class)
        ->assertSeeHtml('data-test="material-modal"')
        ->assertSee('Academix');
});

test('the quick view applies the colourway it is given and reports the command', function () {
    $session = ClientSession::create([
        'user_id' => $this->viewer->id, 'platform' => 'revit', 'machine' => 'HARRISON-VM',
        'app_version' => '2027', 'document' => 'Tower A.rvt', 'last_seen_at' => now(),
    ]);

    $page = Livewire::actingAs($this->viewer)
        ->test(QuickView::class)
        ->call('openQuick', $this->material->code)
        ->assertSet('consumerSessionId', $session->id)
        ->call('applyToConsumer', $this->slate->id)
        ->assertSee('Queued');

    $command = ClientCommand::sole();
    expect($command->payload['variant'])->toBe($this->slate->code)
        ->and($command->payload['material'])->toBe($this->material->code)
        ->and($command->client_session_id)->toBe($session->id)
        ->and($page->get('consumerCommandId'))->toBe($command->id);

    $command->forceFill(['status' => 'done', 'message' => 'Applied to Carpet'])->save();
    $page->call('consumerCommandUpdated')->assertSee('Applied to Carpet');
});

test('the quick view says why it cannot apply, and refuses to', function () {
    // No compatible app connected.
    Livewire::actingAs($this->viewer)
        ->test(QuickView::class)
        ->call('openQuick', $this->material->code)
        ->assertSee('No compatible app connected')
        ->call('applyToConsumer', $this->ashen->id);
    expect(ClientCommand::count())->toBe(0);

    // Connected, but the material has nothing on a drive.
    ClientSession::create(['user_id' => $this->viewer->id, 'platform' => 'revit', 'machine' => 'HARRISON-VM', 'last_seen_at' => now()]);
    $draft = Material::factory()->create(['category_id' => Category::query()->where('code', 'CPT')->sole(), 'supplier_id' => Supplier::factory()->create()]);
    $plain = app(AddVariant::class)->handle($draft, ['colourway' => ['value' => 'Plain']]);

    Livewire::actingAs($this->viewer)
        ->test(QuickView::class)
        ->call('openQuick', $draft->code)
        ->assertSee('Publish a version first')
        ->call('applyToConsumer', $plain->id);
    expect(ClientCommand::count())->toBe(0);
});

test('the quick view opens on the colourway the card was showing', function () {
    Livewire::actingAs($this->viewer)
        ->test(QuickView::class)
        ->call('openQuick', $this->material->code, $this->slate->id)
        ->assertSet('quickVariantId', $this->slate->id)
        ->assertSeeHtml($this->slate->code);
});

test('the quick view carries the maps the shared inspector needs', function () {
    Livewire::actingAs($this->viewer)
        ->test(QuickView::class)
        ->call('openQuick', $this->material->code)
        ->assertSeeHtml('data-test="quick-stage"')
        ->assertSeeHtml('data-test="material-map-inspector"')
        ->assertSeeHtml('data-test="material-map-surface"')
        ->assertSeeHtml('materialViewer');

    Livewire::actingAs($this->viewer)
        ->test('pages::materials.show', ['material' => $this->material])
        ->assertSeeHtml('data-test="material-viewer"')
        ->assertSeeHtml('data-test="material-map-inspector"')
        ->assertSee('Surface');

    // A material without canonical maps shows the flat preview only.
    $bare = Material::factory()->create(['category_id' => Category::query()->where('code', 'CPT')->sole(), 'supplier_id' => Supplier::factory()->create()]);
    app(AddVariant::class)->handle($bare, ['colourway' => ['value' => 'Plain']]);

    Livewire::actingAs($this->viewer)
        ->test(QuickView::class)
        ->call('openQuick', $bare->code)
        ->assertSeeHtml('data-test="material-modal"')
        ->assertDontSeeHtml('data-test="quick-stage"');
});

test('a material out of reach never opens', function () {
    $secret = Material::factory()->create(['visibility' => Visibility::Restricted]);

    Livewire::actingAs($this->viewer)
        ->test(QuickView::class)
        ->call('openQuick', $secret->code)
        ->assertDontSeeHtml('data-test="material-modal"');
});

test('opening and polling a preview never render the library grid', function () {
    $this->actingAs($this->viewer)->get(route('materials.index'))
        ->assertOk()->assertSee('data-test="quick-dialog"', false)
        ->assertSee('data-test="swatch-grid"', false);

    Livewire::actingAs($this->viewer)->test(QuickView::class)
        ->call('openQuick', $this->material->code, $this->slate->id, 3)
        ->assertSet('requestId', 3)
        ->assertDontSeeHtml('data-test="swatch-grid"')
        ->call('consumerSessionsUpdated')
        ->assertDontSeeHtml('data-test="swatch-grid"');
});

test('quick view bounds fallback maps and excludes rejected representation history', function () {
    $rejected = app(CreateRepresentation::class)->handle($this->ashen, 'pbr', '4k', [
        'base_color' => app(FileStore::class)->store('rejected history', 'rejected.png'),
    ]);
    app(ReviewRepresentation::class)->handle($rejected, ReviewState::Rejected);
    $page = Livewire::actingAs($this->viewer)->test(QuickView::class)
        ->call('openQuick', $this->material->code);
    $sets = $page->get('quickViewerSets');
    expect($sets[$this->ashen->id]['base_color'])->toContain('/file-previews/')->toEndWith('/1024');
    expect($sets[$this->ashen->id]['key'])->not->toStartWith($rejected->id.':');
});
