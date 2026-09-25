<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Enums\Role;
use App\Models\Category;
use App\Models\Drive;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\LibrarySeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function e2ePng(int $grey, int $size = 64): UploadedFile
{
    $image = imagecreatetruecolor($size, $size);
    imagefilledrectangle($image, 0, 0, $size, $size, imagecolorallocate($image, $grey, $grey, $grey));
    ob_start();
    imagepng($image);

    return UploadedFile::fake()->createWithContent('map.png', (string) ob_get_clean());
}

/**
 * Upload → review → package/cache worker → publish → drive manifest,
 * ending with a manifest PrismFS can mount.
 */
test('a material travels from upload to a drive manifest through the UI', function () {
    Storage::fake(config('opal.files_disk'));
    $this->seed(LibrarySeeder::class);
    app(SyncRolesAndPermissions::class)->handle();

    $tenant = Tenant::factory()->create(['name' => 'Olsyn']);
    $matt = User::factory()->withTenant($tenant, Role::Editor)->create(['name' => 'Matt']);
    $harrison = User::factory()->withTenant($tenant, Role::Admin)->create(['name' => 'Harrison']);
    $tenant->makeCurrent();

    // 1. Matt uploads the canonical maps.
    Livewire::actingAs($matt)
        ->test('pages::materials.create')
        ->set('name', 'Academix')
        ->set('category_id', (string) Category::query()->where('code', 'CPT')->sole()->getKey())
        ->set('new_supplier', 'Tarkett')
        ->set('colourway', 'Ashen')
        ->set('maps.base_color', e2ePng(110, 128))
        ->set('maps.normal', e2ePng(128, 128))
        ->set('maps.roughness', e2ePng(190, 128))
        ->call('save')
        ->assertHasNoErrors();

    $material = Material::resolveCode('CPT-TARKETT-ACADEMIX');
    $variant = $material?->variants->first();
    $canonical = $variant?->representations()->sole();

    expect($canonical?->review_state->value)->toBe('candidate');

    // 2. Harrison approves the authoring set. The package worker then seals it
    // into USDZ and builds the Revit cache (represented by the worker helper).
    $page = Livewire::actingAs($harrison)
        ->test('pages::materials.show', ['material' => $material])
        ->call('review', $canonical?->getKey(), 'approved')
        ->assertHasNoErrors();

    $quality = $canonical?->quality->slug ?? 'preview';
    $package = publishablePackage($variant, $quality);

    // 3. Nothing is projected until a version is published.
    Livewire::actingAs($harrison)
        ->test('pages::drives.index')
        ->set('name', 'Studio share')
        ->call('create')
        ->assertHasNoErrors();

    $drive = Drive::query()->where('slug', 'studio-share')->sole();

    $this->actingAs($harrison)->get(route('drives.manifest', $drive))->assertSee('files: []', false);

    $page->call('publish')->assertHasNoErrors();

    expect($material?->fresh()?->currentVersion?->number)->toBe(1);

    // 4. New drives serve package-derived files at permanent versioned paths.
    $manifest = $this->actingAs($harrison)->get(route('drives.manifest', $drive))->assertOk()->getContent();

    $directory = '/materials/by-id/'.$material->uuid.'/'.$variant->uuid.'/v1/revit/'.$quality.'/'.$package->derivatives()->sole()->uuid;
    expect($drive->path_layout)->toBe('stable')
        ->and($manifest)->toContain('path: "'.$directory.'/base_color.png"')
        ->and($manifest)->toContain('path: "'.$directory.'/bump.png"')
        ->and($manifest)->toContain('path: "'.$directory.'/glossiness.png"')
        ->and($manifest)->toContain('bucket: "prismfs-dev"')
        ->and(substr_count($manifest, '  - path: '))->toBe(8)
        ->and($material?->fresh()?->currentVersion?->packageFor($variant)?->is($package))->toBeTrue();

    // 5. Restricting the material hides it from the drive until the drive is granted.
    $page->call('setVisibility', 'restricted');
    $this->actingAs($harrison)->get(route('drives.manifest', $drive))->assertSee('files: []', false);

    $page->set('grantDrive', (string) $drive->getKey())->call('grantDrive')->assertHasNoErrors();
    $this->actingAs($harrison)->get(route('drives.manifest', $drive))->assertDontSee('files: []', false);

    // 6. Authoring and approval remain in the provenance timeline. Package
    // lineage is carried by the pinned package hash and derivative cache key.
    $page->assertSee('Matt uploaded')
        ->assertSee('Harrison approved');

    Tenant::forgetCurrent();
});
