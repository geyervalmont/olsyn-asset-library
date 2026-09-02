<?php

use App\Actions\Materials\AddVariant;
use App\Actions\Representations\CreateRepresentation;
use App\Actions\Representations\DeriveRepresentation;
use App\Actions\Representations\ReviewRepresentation;
use App\Actions\Versions\CutVersion;
use App\Actions\Versions\PublishVersion;
use App\Actions\Visibility\GrantMaterialAccess;
use App\Actions\Visibility\SetMaterialVisibility;
use App\Enums\ReviewState;
use App\Enums\Visibility;
use App\Library\Drives\DriveNamespace;
use App\Library\FileStore;
use App\Models\Category;
use App\Models\Drive;
use App\Models\Material;
use App\Models\Supplier;
use App\Models\Target;
use Database\Seeders\LibrarySeeder;
use Illuminate\Support\Facades\Storage;

function solidPng(int $grey): string
{
    $image = imagecreatetruecolor(2, 2);
    imagefilledrectangle($image, 0, 0, 2, 2, imagecolorallocate($image, $grey, $grey, $grey));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

beforeEach(function () {
    Storage::fake(config('opal.files_disk'));
    $this->seed(LibrarySeeder::class);

    $store = app(FileStore::class);
    $carpet = Category::query()->where('code', 'CPT')->sole();
    $this->material = Material::factory()->create(['name' => 'Academix', 'category_id' => $carpet, 'supplier_id' => Supplier::factory()->create(['name' => 'Tarkett'])]);
    $this->ashen = app(AddVariant::class)->handle($this->material, ['colourway' => 'Ashen']);

    $approve = fn ($representation) => app(ReviewRepresentation::class)->handle($representation, ReviewState::Approved);
    $approve(app(CreateRepresentation::class)->handle($this->ashen, 'pbr', '2k', [
        'base_color' => $store->store(solidPng(100), 'base.png'),
        'roughness' => $store->store(solidPng(180), 'rough.png'),
    ]));
    $approve(app(DeriveRepresentation::class)->handle($this->ashen, 'revit', '2k'));
    $approve(app(DeriveRepresentation::class)->handle($this->ashen, 'omniverse', '2k'));
});

test('a drive projects the current version of visible materials as a prismfs manifest', function () {
    $drive = Drive::factory()->create(['name' => 'Studio share', 'root_path' => 'materials']);
    $namespace = app(DriveNamespace::class);

    expect($namespace->entries($drive))->toBe([])
        ->and($namespace->toYaml($drive))->toBe("version: 1\nfiles: []\n");

    app(PublishVersion::class)->handle(app(CutVersion::class)->handle($this->material));

    $entries = $namespace->entries($drive);
    $paths = array_column($entries, 'path');

    expect($paths)->toBe([
        '/materials/Carpet/Academix/Ashen/omniverse/CPT-TARKETT-ACADEMIX-ASHEN.mdl',
        '/materials/Carpet/Academix/Ashen/omniverse/CPT-TARKETT-ACADEMIX-ASHEN_base_color.png',
        '/materials/Carpet/Academix/Ashen/omniverse/CPT-TARKETT-ACADEMIX-ASHEN_roughness.png',
        '/materials/Carpet/Academix/Ashen/pbr/CPT-TARKETT-ACADEMIX-ASHEN_base_color.png',
        '/materials/Carpet/Academix/Ashen/pbr/CPT-TARKETT-ACADEMIX-ASHEN_roughness.png',
        '/materials/Carpet/Academix/Ashen/revit/CPT-TARKETT-ACADEMIX-ASHEN_base_color.png',
        '/materials/Carpet/Academix/Ashen/revit/CPT-TARKETT-ACADEMIX-ASHEN_glossiness.png',
    ]);

    $base = collect($entries)->firstWhere('path', $paths[3]);
    $file = $this->ashen->representations()->first()?->fileFor('base_color');

    expect($base['object'])->toBe(['bucket' => 'prismfs-dev', 'key' => $file?->object_key, 'size' => $file?->bytes, 'version' => null])
        ->and($namespace->toYaml($drive))->toContain('  - path: "/materials/Carpet/Academix/Ashen/pbr/CPT-TARKETT-ACADEMIX-ASHEN_base_color.png"')
        ->and($namespace->toYaml($drive))->toContain('      key: "'.$file?->object_key.'"');

    $revitOnly = Drive::factory()->create(['target_id' => Target::fromSlug('revit')->getKey()]);

    expect(array_column($namespace->entries($revitOnly), 'path'))->toBe([$paths[5], $paths[6]]);
});

test('restricted materials appear only on drives that were granted access', function () {
    app(PublishVersion::class)->handle(app(CutVersion::class)->handle($this->material));
    app(SetMaterialVisibility::class)->handle($this->material, Visibility::Restricted);
    $drive = Drive::factory()->create();
    $namespace = app(DriveNamespace::class);

    expect($namespace->entries($drive))->toBe([]);

    app(GrantMaterialAccess::class)->handle($this->material, $drive);

    expect($namespace->entries($drive))->toHaveCount(7);

    $this->artisan('opal:drive:manifest', ['drive' => $drive->slug])
        ->expectsOutputToContain('/materials/Carpet/Academix/Ashen/revit/CPT-TARKETT-ACADEMIX-ASHEN_glossiness.png')
        ->assertSuccessful();

    $this->artisan('opal:drive:manifest', ['drive' => 'nope'])->assertFailed();
});
