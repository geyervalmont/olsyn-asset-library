<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Actions\Materials\AddVariant;
use App\Actions\Representations\CreateRepresentation;
use App\Actions\Representations\ReviewRepresentation;
use App\Actions\Versions\CutVersion;
use App\Actions\Versions\PublishVersion;
use App\Actions\Visibility\GrantMaterialAccess;
use App\Actions\Visibility\SetMaterialVisibility;
use App\Enums\ReviewState;
use App\Enums\Role;
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
use App\Models\Tenant;
use App\Models\User;
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

test('stable shared and personal projections include the same canonical objects and current named aliases', function () {
    app(SyncRolesAndPermissions::class)->handle();
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();
    $user = User::factory()->withTenant($tenant, Role::Editor)->create();
    $drive = Drive::factory()->create(['path_layout' => 'stable']);
    $namespace = app(DriveNamespace::class);
    config(['filesystems.disks.canonical.bucket' => 'canonical-bucket', 'opal.packages_disk' => 'canonical']);
    $package = publishablePackage($this->ashen);
    app(PublishVersion::class)->handle(app(CutVersion::class)->handle($this->material));
    $files = $namespace->projectionEntries($drive);
    expect($files)->toHaveCount(6)->toBe($namespace->projectionEntriesForUser($user));
    $canonical = collect($files)->where('role', 'package')->values();
    expect($canonical)->toHaveCount(2)
        ->and($canonical[0]['path'])->toBe('/materials/by-id/'.$this->material->uuid.'/'.$this->ashen->uuid.'/v1/canonical/'.$package->sha256.'.usdz')
        ->and($canonical[1]['path'])->toBe('/materials/by-name/Carpet/Academix/Ashen/canonical/material.usdz')
        ->and($canonical[0]['object'])->toBe(['bucket' => 'canonical-bucket', 'key' => $package->object_key, 'size' => $package->bytes, 'version' => null])
        ->and($canonical[1]['object'])->toBe($canonical[0]['object']);
    expect($namespace->manifest($drive)['files'])->toHaveCount(6)
        ->and($namespace->toYaml($drive))->toContain($canonical[1]['path']);
    $paths = array_column($files, 'path');
    $sorted = $paths;
    sort($sorted, SORT_STRING);
    expect($paths)->toBe($sorted)->and($namespace->projectionEntries($drive))->toBe($files);

    $token = $drive->issueToken();
    $url = route('prismfs.drives.manifest', $drive);
    $first = $this->withToken($token)->get($url)->assertOk();
    $this->withHeader('If-None-Match', $first->headers->get('ETag'))->get($url)->assertStatus(304);
    $this->material->update(['name' => 'Renamed stone']);
    $this->get($url)->assertOk()->assertSee('/by-name/Carpet/Renamed stone/', false);
    $renamed = $namespace->projectionEntries($drive);
    $pinned = array_filter($paths, fn ($path) => str_contains($path, '/by-id/'));
    expect(array_diff($pinned, array_column($renamed, 'path')))->toBe([]);
    $this->material->update(['visibility' => Visibility::Restricted]);
    $this->get($url)->assertOk()->assertSee('files: []', false);
    expect($namespace->projectionEntries($drive))->toBe([])->and($namespace->projectionEntriesForUser($user))->toBe([]);
    app(GrantMaterialAccess::class)->handle($this->material, $user);
    expect($namespace->projectionEntriesForUser($user))->toHaveCount(6)->and($namespace->projectionEntries($drive))->toBe([]);
    app(GrantMaterialAccess::class)->handle($this->material, $drive);
    expect($namespace->projectionEntries($drive))->toBe($namespace->projectionEntriesForUser($user));
    $drive->grants()->delete();
    expect($namespace->projectionEntries($drive))->toBe([]);
    Tenant::forgetCurrent();
});

test('full projections honor custom roots and target scope without changing legacy layouts', function () {
    $namespace = app(DriveNamespace::class);
    publishablePackage($this->ashen);
    app(PublishVersion::class)->handle(app(CutVersion::class)->handle($this->material));
    $drive = Drive::factory()->create(['path_layout' => 'stable', 'root_path' => '/studio/library']);
    $files = $namespace->projectionEntries($drive);
    expect($files)->toHaveCount(6);
    foreach ($files as $file) {
        expect($file['path'])->toStartWith('/studio/library/');
    }
    $drive->update(['root_path' => '/']);
    foreach ($namespace->projectionEntries($drive) as $file) {
        expect($file['path'])->toStartWith('/by-')->not->toStartWith('//');
    }
    $drive->update(['target_id' => Target::fromSlug('revit')->id]);
    expect($namespace->projectionEntries($drive))->toHaveCount(4)
        ->and(array_unique(array_column($namespace->projectionEntries($drive), 'target')))->toBe(['revit']);
    $drive->update(['target_id' => Target::fromSlug('omniverse')->id]);
    expect($namespace->projectionEntries($drive))->toHaveCount(2)
        ->and(array_unique(array_column($namespace->projectionEntries($drive), 'role')))->toBe(['package']);
    $drive->update(['path_layout' => 'named', 'target_id' => null]);
    expect($namespace->projectionEntries($drive))->toBe($namespace->entries($drive))->toHaveCount(2);
});

test('shared aliases advance publications and converters while all published by-id objects survive', function () {
    $drive = Drive::factory()->create(['path_layout' => 'stable']);
    $namespace = app(DriveNamespace::class);
    $package = publishablePackage($this->ashen);
    app(PublishVersion::class)->handle(app(CutVersion::class)->handle($this->material));
    $original = collect($namespace->projectionEntries($drive))->filter(fn ($f) => str_contains($f['path'], '/by-id/'))->pluck('path')->all();
    $new = PackageDerivative::factory()->for($package)->create(['built_at' => now()->addMinute(), 'converter_version' => '2.0.0']);
    $new->derivativeFiles()->create(['file_id' => $this->base->id, 'map_role_id' => MapRole::fromSlug('base_color')->id]);
    $aliases = collect($namespace->projectionEntries($drive))->filter(fn ($f) => str_contains($f['path'], '/by-name/'));
    expect($aliases)->toHaveCount(2)->and($aliases->firstWhere('role', 'base_color')['derivative_uuid'])->toBe($new->uuid);
    $nextPackage = publishablePackage($this->ashen);
    $next = app(CutVersion::class)->handle($this->material);
    // A draft revision changes neither canonical aliases nor stable history.
    expect(collect($namespace->projectionEntries($drive))->where('role', 'package')->pluck('sha256')->unique()->all())->toBe([$package->sha256]);
    app(PublishVersion::class)->handle($next);
    $files = collect($namespace->projectionEntries($drive));
    expect(array_diff($original, $files->pluck('path')->all()))->toBe([]);
    $aliases = $files->filter(fn ($f) => str_contains($f['path'], '/by-name/'));
    expect($aliases->pluck('material_version')->unique()->values()->all())->toBe([2])
        ->and($aliases->firstWhere('role', 'package')['sha256'])->toBe($nextPackage->sha256);
});
