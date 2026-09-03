<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Actions\Materials\AddVariant;
use App\Actions\Platforms\AssignPlatformIdentity;
use App\Actions\Representations\CreateRepresentation;
use App\Actions\Representations\ReviewRepresentation;
use App\Actions\Versions\CutVersion;
use App\Actions\Versions\PublishVersion;
use App\Enums\ReviewState;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Library\FileStore;
use App\Models\Category;
use App\Models\Drive;
use App\Models\Material;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\LibrarySeeder;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake(config('opal.files_disk'));
    $this->seed(LibrarySeeder::class);
    app(SyncRolesAndPermissions::class)->handle();
    $this->tenant = Tenant::factory()->create();
    $this->viewer = User::factory()->withTenant($this->tenant, Role::Viewer)->create();

    $carpet = Category::query()->where('code', 'CPT')->sole();
    $this->material = Material::factory()->create(['name' => 'Academix', 'category_id' => $carpet, 'supplier_id' => Supplier::factory()->create(['name' => 'Tarkett']), 'supplier_product_code' => '634014']);
    $this->ashen = app(AddVariant::class)->handle($this->material, ['colourway' => ['value' => 'Ashen', 'supplier_code' => '634014001']]);
    $image = imagecreatetruecolor(4, 4);
    ob_start();
    imagepng($image);
    $this->file = app(FileStore::class)->store((string) ob_get_clean(), 'base.png');
    app(CreateRepresentation::class)->handle($this->ashen, 'pbr', '1k', ['base_color' => $this->file]);
    Material::factory()->create(['name' => 'Secret stone', 'visibility' => Visibility::Restricted]);
});

test('the api requires a token', function () {
    $this->getJson('/api/v1/materials')->assertUnauthorized();
});

test('the api searches, shows and resolves within the token users visibility', function () {
    Sanctum::actingAs($this->viewer);

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('email', $this->viewer->email);

    $this->getJson('/api/v1/materials?q=acadmix')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'CPT-TARKETT-ACADEMIX')
        ->assertJsonPath('data.0.supplier.code', 'TARKETT')
        ->assertJsonPath('meta.total', 1);

    $this->getJson('/api/v1/materials')->assertOk()->assertJsonMissing(['name' => 'Secret stone']);
    $this->getJson('/api/v1/materials?category=CPT&status=draft')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/materials?status=bogus')->assertUnprocessable();

    $this->getJson('/api/v1/materials/cpt-tarkett-academix')
        ->assertOk()
        ->assertJsonPath('data.variants.0.code', 'CPT-TARKETT-ACADEMIX-ASHEN')
        ->assertJsonPath('data.variants.0.attributes.0.supplier_code', '634014001')
        ->assertJsonPath('data.variants.0.representations.0.target', 'pbr')
        ->assertJsonPath('data.variants.0.representations.0.files.0.role', 'base_color')
        ->assertJsonPath('data.variants.0.representations.0.files.0.url', $this->file->url());

    $this->getJson('/api/v1/materials/NOPE-NOPE-NOPE')->assertNotFound();

    app(AssignPlatformIdentity::class)->handle($this->ashen, 'revit', 'rvt-guid-1', 'Carpet - Academix Ashen');

    $this->getJson('/api/v1/variants/resolve?platform=revit&reference=rvt-guid-1')->assertOk()->assertJsonPath('data.code', 'CPT-TARKETT-ACADEMIX-ASHEN');
    $this->getJson('/api/v1/variants/resolve?platform=revit&reference='.urlencode('Anything [CPT-TARKETT-ACADEMIX-ASHEN]'))->assertOk()->assertJsonPath('data.material_code', 'CPT-TARKETT-ACADEMIX');
    $this->getJson('/api/v1/variants/resolve?platform=revit&reference=unknown')->assertNotFound();
    $this->getJson('/api/v1/variants/resolve?platform=nope&reference=x')->assertUnprocessable();
    $this->getJson('/api/v1/variants/CPT-TARKETT-ACADEMIX-ASHEN')->assertOk()->assertJsonPath('data.tile_width_mm', null);
});

test('users create and revoke api tokens from settings and the docs render', function () {
    $this->tenant->makeCurrent();

    $page = Livewire::actingAs($this->viewer)
        ->test('pages::settings.api-tokens')
        ->set('name', 'pyRevit')
        ->call('create')
        ->assertHasNoErrors()
        ->assertSee('data-test="new-token"', false)
        ->assertSee('pyRevit');

    $plain = $page->get('plainTextToken');

    $this->withToken($plain)->getJson('/api/v1/me')->assertOk();

    $token = $this->viewer->tokens()->sole();
    $page->call('revoke', $token->id);
    $this->app['auth']->forgetGuards();

    $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();

    $this->actingAs($this->viewer)->get('/docs/api')->assertOk();
    $this->actingAs($this->viewer)->get('/docs/api.json')->assertOk()->assertJsonPath('info.title', 'OPAL API')->assertJsonPath('paths./v1/materials.get.summary', 'Search the library');
});

test('clients can list drives, find a variant on a drive, and write platform identities back', function () {
    Storage::fake(config('opal.files_disk'));
    app(ReviewRepresentation::class)->handle($this->ashen->representations()->sole(), ReviewState::Approved);
    app(PublishVersion::class)->handle(app(CutVersion::class)->handle($this->material));
    $drive = Drive::factory()->create(['name' => 'Studio share', 'root_path' => '/materials']);
    Sanctum::actingAs($this->viewer);

    $this->getJson('/api/v1/drives')->assertOk()->assertJsonPath('data.0.slug', 'studio-share')->assertJsonPath('data.0.root_path', '/materials');

    $this->getJson('/api/v1/variants/CPT-TARKETT-ACADEMIX-ASHEN/paths?drive=studio-share')
        ->assertOk()
        ->assertJsonPath('data.published', true)
        ->assertJsonPath('data.files.0.path', '/materials/Carpet/Academix/Ashen/pbr/CPT-TARKETT-ACADEMIX-ASHEN_base_color.png')
        ->assertJsonPath('data.files.0.role', 'base_color')
        ->assertJsonPath('data.files.0.sha256', $this->file->sha256);

    $this->getJson('/api/v1/variants/CPT-TARKETT-ACADEMIX-ASHEN/paths?drive=nope')->assertUnprocessable();

    $this->postJson('/api/v1/variants/CPT-TARKETT-ACADEMIX-ASHEN/identities', ['platform' => 'revit', 'external_id' => 'guid-1', 'external_name' => 'Carpet - Academix Ashen', 'payload' => ['document' => 'Tower A']])
        ->assertCreated()
        ->assertJsonPath('data.platform', 'revit');

    $this->getJson('/api/v1/variants/resolve?platform=revit&reference=guid-1')->assertOk()->assertJsonPath('data.code', 'CPT-TARKETT-ACADEMIX-ASHEN');
    $this->postJson('/api/v1/variants/CPT-TARKETT-ACADEMIX-ASHEN/identities', ['platform' => 'nope'])->assertUnprocessable();

    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/variants/CPT-TARKETT-ACADEMIX-ASHEN/identities', ['platform' => 'revit', 'external_id' => 'x'])->assertForbidden();
});
