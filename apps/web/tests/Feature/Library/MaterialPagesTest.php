<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Actions\Materials\AddVariant;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Models\Category;
use App\Models\Drive;
use App\Models\Material;
use App\Models\Supplier;
use App\Models\Target;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\LibrarySeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function fakePng(int $grey, int $size = 64): UploadedFile
{
    $image = imagecreatetruecolor($size, $size);
    imagefilledrectangle($image, 0, 0, $size, $size, imagecolorallocate($image, $grey, $grey, $grey));
    ob_start();
    imagepng($image);

    return UploadedFile::fake()->createWithContent('map.png', (string) ob_get_clean());
}

beforeEach(function () {
    Storage::fake(config('opal.files_disk'));
    $this->seed(LibrarySeeder::class);
    app(SyncRolesAndPermissions::class)->handle();

    $this->tenant = Tenant::factory()->create();
    $this->viewer = User::factory()->withTenant($this->tenant, Role::Viewer)->create();
    $this->editor = User::factory()->withTenant($this->tenant, Role::Editor)->create();
    $this->admin = User::factory()->withTenant($this->tenant, Role::Admin)->create();
    $this->tenant->makeCurrent();
});

afterEach(fn () => Tenant::forgetCurrent());

test('the library page lists visible materials and searches them', function () {
    $carpet = Category::query()->where('code', 'CPT')->sole();
    $academix = Material::factory()->create(['name' => 'Academix', 'category_id' => $carpet, 'supplier_id' => Supplier::factory()->create(['name' => 'Tarkett'])]);
    app(AddVariant::class)->handle($academix, ['colourway' => 'Ashen']);
    $oak = Material::factory()->create(['name' => 'Oak plank']);
    $secret = Material::factory()->create(['name' => 'Secret stone', 'visibility' => Visibility::Restricted]);

    $this->actingAs($this->viewer)
        ->get(route('materials.index'))
        ->assertOk()
        ->assertSee('Academix')
        ->assertSee('Oak plank')
        ->assertDontSee('Secret stone')
        ->assertDontSee('data-test="add-material"', false);

    $this->actingAs($this->editor)->get(route('materials.index'))->assertSee('data-test="add-material"', false);

    Livewire::actingAs($this->viewer)
        ->test('pages::materials.index')
        ->set('search', 'ashen')
        ->assertSee('Academix')
        ->assertDontSee('Oak plank')
        ->set('search', '')
        ->set('category', (string) $carpet->getKey())
        ->assertSee('Academix')
        ->assertDontSee('Oak plank')
        ->set('category', '')
        ->set('search', 'nothing-like-this')
        ->assertSee('No materials match');
});

test('a user without a workspace is sent to the dashboard, and viewers cannot upload', function () {
    $this->actingAs(User::factory()->create())->get(route('materials.index'))->assertRedirect(route('dashboard'));
    $this->actingAs($this->viewer)->get(route('materials.create'))->assertForbidden();
    $this->actingAs($this->editor)->get(route('materials.create'))->assertOk();
});

test('the upload page creates a material, its variant, canonical maps and provenance', function () {
    $carpet = Category::query()->where('code', 'CPT')->sole();

    Livewire::actingAs($this->editor)
        ->test('pages::materials.create')
        ->set('name', 'Academix')
        ->set('category_id', (string) $carpet->getKey())
        ->set('new_supplier', 'Tarkett')
        ->set('supplier_product_code', '634014')
        ->set('colourway', 'Ashen')
        ->set('colourway_code', '634014001')
        ->set('maps.base_color', fakePng(120, 256))
        ->set('maps.roughness', fakePng(200, 256))
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('materials.show', 'CPT-TARKETT-ACADEMIX'));

    $material = Material::resolveCode('CPT-TARKETT-ACADEMIX');
    $variant = $material?->variants->first();
    $representation = $variant?->representations()->sole();

    expect($material?->supplier?->name)->toBe('Tarkett')
        ->and($material?->contributed_by_user_id)->toBe($this->editor->getKey())
        ->and($material?->contributed_by_tenant_id)->toBe($this->tenant->getKey())
        ->and($variant?->code)->toBe('CPT-TARKETT-ACADEMIX-ASHEN')
        ->and($variant?->attributeValue('colourway'))->toBe('Ashen')
        ->and($representation?->target->slug)->toBe('pbr')
        ->and($representation?->quality->pixels)->toBe(256)
        ->and(array_keys($representation?->filesByRole() ?? []))->toBe(['base_color', 'roughness'])
        ->and($variant?->provenanceEvents()->sole()->action)->toBe('uploaded')
        ->and($variant?->provenanceEvents()->sole()->actor?->is($this->editor))->toBeTrue()
        ->and($variant?->provenanceEvents()->sole()->outputs()->count())->toBe(2);

    Storage::disk(config('opal.files_disk'))->assertExists($representation?->fileFor('base_color')?->object_key ?? 'missing');

    Livewire::actingAs($this->editor)
        ->test('pages::materials.create')
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name', 'category_id']);
});

test('the material page shows the record and enforces visibility', function () {
    $material = Material::factory()->create(['name' => 'Travertine', 'visibility' => Visibility::Restricted]);
    app(AddVariant::class)->handle($material, ['finish' => 'Honed']);

    $this->actingAs($this->viewer)->get(route('materials.show', $material))->assertForbidden();
    $this->actingAs($this->admin)->get(route('materials.show', $material))->assertOk()->assertSee('Travertine')->assertSee('Honed');

    $this->tenant->makeCurrent();
    Livewire::actingAs($this->admin)
        ->test('pages::materials.show', ['material' => $material])
        ->call('grantUser')
        ->assertHasErrors('grantEmail')
        ->set('grantEmail', $this->viewer->email)
        ->call('grantUser')
        ->assertHasNoErrors();

    $this->actingAs($this->viewer)->get(route('materials.show', $material))->assertOk();

    $this->tenant->makeCurrent();
    Livewire::actingAs($this->admin)
        ->test('pages::materials.show', ['material' => $material])
        ->call('setVisibility', 'library')
        ->set('newColourway', 'Slate')
        ->call('addVariant')
        ->assertHasNoErrors();

    expect($material->fresh()?->visibility)->toBe(Visibility::Library)
        ->and($material->variants()->count())->toBe(2);
});

test('drives are registered on their page and expose a manifest', function () {
    $this->actingAs($this->editor)->get(route('drives.index'))->assertForbidden();

    $this->tenant->makeCurrent();
    Livewire::actingAs($this->admin)
        ->test('pages::drives.index')
        ->assertSee('No drives registered yet')
        ->set('name', 'Studio Revit share')
        ->set('root_path', 'revit')
        ->set('target_id', (string) Target::fromSlug('revit')->getKey())
        ->call('create')
        ->assertHasNoErrors()
        ->assertSee('Studio Revit share');

    $drive = Drive::query()->where('slug', 'studio-revit-share')->sole();

    expect($drive->root_path)->toBe('/revit')
        ->and($drive->target?->slug)->toBe('revit');

    $this->actingAs($this->admin)
        ->get(route('drives.manifest', $drive))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/yaml; charset=utf-8')
        ->assertSee("version: 1\nfiles: []", false);
});
