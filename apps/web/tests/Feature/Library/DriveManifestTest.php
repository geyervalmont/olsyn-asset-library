<?php

use App\Actions\Materials\AddVariant;
use App\Actions\Representations\CreateRepresentation;
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
use App\Models\MapRole;
use App\Models\Material;
use App\Models\PackageDerivative;
use App\Models\Supplier;
use App\Models\Target;
use Database\Seeders\LibrarySeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
    $this->base = $store->store(solidPng(100), 'base.png');
    $this->roughness = $store->store(solidPng(180), 'rough.png');
    $approve(app(CreateRepresentation::class)->handle($this->ashen, 'pbr', '2k', [
        'base_color' => $this->base,
        'roughness' => $this->roughness,
    ]));
});

test('a drive projects the current version of visible materials as a prismfs manifest', function () {
    $drive = Drive::factory()->create(['name' => 'Studio share', 'root_path' => 'materials']);
    $namespace = app(DriveNamespace::class);

    expect($namespace->entries($drive))->toBe([])
        ->and($namespace->toYaml($drive))->toBe("version: 1\nfiles: []\n");

    $package = publishablePackage($this->ashen, '2k');
    app(PublishVersion::class)->handle(app(CutVersion::class)->handle($this->material));

    // A cache row that cannot prove it came from the pinned bytes must never
    // win the friendly path, even if it is newer than the verified cache.
    $unverified = PackageDerivative::factory()->for($package)->create([
        'source_sha256' => str_repeat('0', 64),
        'converter_version' => '99.0.0',
        'built_at' => now()->addMinute(),
    ]);
    $unverified->derivativeFiles()->create([
        'file_id' => $this->roughness->getKey(),
        'map_role_id' => MapRole::fromSlug('base_color')->getKey(),
        'colour_space' => 'srgb',
    ]);

    $entries = $namespace->entries($drive);
    $paths = array_column($entries, 'path');

    expect($paths)->toBe([
        '/materials/Carpet/Academix/Ashen/revit/2k/CPT-TARKETT-ACADEMIX-ASHEN_base_color.png',
        '/materials/Carpet/Academix/Ashen/revit/2k/CPT-TARKETT-ACADEMIX-ASHEN_glossiness.png',
    ]);

    $base = collect($entries)->firstWhere('path', $paths[0]);

    expect($base['object'])->toBe(['bucket' => 'prismfs-dev', 'key' => $this->base->object_key, 'size' => $this->base->bytes, 'version' => null])
        ->and($base['source_package_sha256'])->toBe($package->sha256)
        ->and($base['converter'])->toBe('usd-toolbox:revit')
        ->and($namespace->toYaml($drive))->toContain('  - path: "/materials/Carpet/Academix/Ashen/revit/2k/CPT-TARKETT-ACADEMIX-ASHEN_base_color.png"')
        ->and($namespace->toYaml($drive))->toContain('      key: "'.$this->base->object_key.'"');

    $revitOnly = Drive::factory()->create(['target_id' => Target::fromSlug('revit')->getKey()]);

    expect(array_column($namespace->entries($revitOnly), 'path'))->toBe($paths);
});

test('restricted materials appear only on drives that were granted access', function () {
    publishablePackage($this->ashen, '2k');
    app(PublishVersion::class)->handle(app(CutVersion::class)->handle($this->material));
    app(SetMaterialVisibility::class)->handle($this->material, Visibility::Restricted);
    $drive = Drive::factory()->create();
    $namespace = app(DriveNamespace::class);

    expect($namespace->entries($drive))->toBe([]);

    app(GrantMaterialAccess::class)->handle($this->material, $drive);

    expect($namespace->entries($drive))->toHaveCount(2);

    $this->artisan('opal:drive:manifest', ['drive' => $drive->slug])
        ->expectsOutputToContain('/materials/Carpet/Academix/Ashen/revit/2k/CPT-TARKETT-ACADEMIX-ASHEN_glossiness.png')
        ->assertSuccessful();

    $this->artisan('opal:drive:manifest', ['drive' => 'nope'])->assertFailed();
});

test('stable drive paths survive renames and retain published versions and converter generations', function () {
    $drive = Drive::factory()->create(['path_layout' => 'stable']);
    $namespace = app(DriveNamespace::class);
    $package = publishablePackage($this->ashen);
    $first = app(PublishVersion::class)->handle(app(CutVersion::class)->handle($this->material));
    $original = $namespace->entries($drive);
    $derivative = $package->derivatives()->firstOrFail();
    expect($original)->toHaveCount(2)
        ->and($original[0]['path'])->toStartWith('/materials/by-id/'.$this->material->uuid.'/'.$this->ashen->uuid.'/v1/revit/2k/'.$derivative->uuid.'/')
        ->and(Str::isUuid($derivative->uuid, version: 7))->toBeTrue();

    $this->material->update(['name' => 'New material name']);
    $this->ashen->update(['name' => 'New colour name']);
    expect(array_column($namespace->entries($drive), 'path'))->toBe(array_column($original, 'path'));

    $next = PackageDerivative::factory()->for($package)->create(['converter_version' => '2.0.0', 'built_at' => now()->addMinute()]);
    foreach ($derivative->derivativeFiles as $item) {
        $next->derivativeFiles()->create(['file_id' => $item->file_id, 'map_role_id' => $item->map_role_id, 'colour_space' => $item->colour_space]);
    }
    expect($namespace->entries($drive))->toHaveCount(4)
        ->and(array_unique(array_column($namespace->entriesForVariant($drive, $this->ashen, $first->number), 'derivative_uuid')))->toBe([$next->uuid]);

    publishablePackage($this->ashen);
    $second = app(PublishVersion::class)->handle(app(CutVersion::class)->handle($this->material));
    $entries = $namespace->entries($drive);
    expect($entries)->toHaveCount(6)
        ->and(array_diff(array_column($original, 'path'), array_column($entries, 'path')))->toBe([])
        ->and(array_unique(array_column($namespace->entriesForVariant($drive, $this->ashen), 'material_version')))->toBe([$second->number])
        ->and($namespace->entriesForVariant($drive, $this->ashen, $first->number))->toHaveCount(2);

    // Unpublished snapshots must not become readable, and revocation still applies to history.
    app(CutVersion::class)->handle($this->material);
    expect($namespace->entries($drive))->toHaveCount(6);
    app(SetMaterialVisibility::class)->handle($this->material, Visibility::Restricted);
    expect($namespace->entries($drive))->toBe([]);
    app(GrantMaterialAccess::class)->handle($this->material, $drive);
    expect($namespace->entries($drive))->toHaveCount(6);
});
