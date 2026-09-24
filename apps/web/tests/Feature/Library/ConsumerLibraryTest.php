<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Actions\Materials\AddVariant;
use App\Actions\Versions\PublishVersion;
use App\Enums\Role;
use App\Enums\VersionStatus;
use App\Enums\Visibility;
use App\Models\File;
use App\Models\MapRole;
use App\Models\Material;
use App\Models\Package;
use App\Models\PackageDerivative;
use App\Models\QualityTier;
use App\Models\Target;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\LibrarySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(LibrarySeeder::class);
    app(SyncRolesAndPermissions::class)->handle();
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();
    $this->user = User::factory()->withTenant($tenant, Role::Viewer)->create();
    Sanctum::actingAs($this->user, ['*']);
    Storage::fake(config('opal.files_disk'));
});

function publishedConsumerMaterial(): array
{
    $material = Material::factory()->create(['visibility' => Visibility::Library]);
    $variant = app(AddVariant::class)->handle($material, ['colourway' => 'Consumer']);
    $package = Package::factory()->for($variant)->create();
    foreach ([['revit', 'preview'], ['revit', '2k'], ['pbr', '4k']] as [$target, $quality]) {
        $derivative = PackageDerivative::factory()->for($package)->create(['target_id' => Target::fromSlug($target)->id, 'quality_tier_id' => QualityTier::fromSlug($quality)->id]);
        $file = File::where('sha256', hash('sha256', '0123456789'))->first() ?? File::factory()->create(['disk' => config('opal.files_disk'), 'bytes' => 10, 'sha256' => hash('sha256', '0123456789')]);
        Storage::disk($file->disk)->put($file->object_key, '0123456789');
        $derivative->derivativeFiles()->create(['file_id' => $file->id, 'map_role_id' => MapRole::fromSlug('base_color')->id]);
    }
    $version = $material->versions()->create(['number' => 1, 'status' => VersionStatus::Draft]);
    $version->packages()->attach($package->id, ['variant_id' => $variant->id]);
    app(PublishVersion::class)->handle($version);

    return [$material, $variant, $package];
}

test('consumer pages stay bounded and do not load texture payloads or issue one query per result', function () {
    for ($i = 0; $i < 5; $i++) {
        publishedConsumerMaterial();
    }
    DB::enableQueryLog();
    $this->getJson('/api/v1/library?per_page=2&page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 5)->assertJsonPath('meta.current_page', 2)
        ->assertJsonMissingPath('data.0.files')->assertJsonStructure(['data' => [['uuid', 'material_uuid', 'preview_url']]]);
    $small = count(DB::getQueryLog());
    DB::flushQueryLog();
    $this->getJson('/api/v1/library?per_page=50')->assertOk()->assertJsonCount(5, 'data');
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual($small + 2);
    DB::disableQueryLog();
    $this->getJson('/api/v1/library/facets')->assertOk()->assertJsonStructure(['data' => ['categories' => [['name', 'code']]]]);
    $this->getJson('/api/v1/library?per_page=500')->assertUnprocessable();
});

test('Revit and Omniverse resolve different quality from exactly the same published identity', function () {
    [$material, $variant, $package] = publishedConsumerMaterial();
    $url = '/api/v1/library/variants/'.$variant->uuid;
    $revit = $this->getJson($url.'/resolve?target=revit')->assertOk()->assertJsonPath('data.quality', 'preview')->json('data');
    $omni = $this->getJson($url.'/resolve?target=omniverse&version=1')->assertOk()->assertJsonPath('data.quality', '4k')->json('data');
    foreach (['material_uuid', 'variant_uuid', 'material_version', 'source_package_sha256'] as $key) {
        expect($revit[$key])->toBe($omni[$key]);
    }
    expect($revit['source_package_sha256'])->toBe($package->sha256);
    $this->get($revit['files'][0]['url'], ['Range' => 'bytes=0-3'])->assertStatus(206)->assertStreamedContent('0123');
    $this->get($url.'/preview')->assertOk()->assertStreamedContent('0123456789');
    $this->getJson($url.'/resolve?target=omniverse&version=999')->assertStatus(409);
    $material->update(['visibility' => Visibility::Restricted]);
    $this->getJson('/api/v1/library')->assertJsonCount(0, 'data');
    $this->getJson($url.'/resolve?target=revit')->assertNotFound();
    $this->get($url.'/preview')->assertNotFound();
    $this->get($revit['files'][0]['url'])->assertNotFound();
});

test('a Revit resolution cannot silently fall back to a large texture', function () {
    [, $variant, $package] = publishedConsumerMaterial();
    $package->derivatives()->where('quality_tier_id', QualityTier::fromSlug('preview')->id)->delete();
    $this->getJson('/api/v1/library/variants/'.$variant->uuid.'/resolve?target=revit')->assertStatus(409);
});

test('existing canonical packages provide Omniverse materials without a derived PBR rebuild', function () {
    [$material, $variant, $package] = publishedConsumerMaterial();
    $package->derivatives()->where('target_id', Target::fromSlug('pbr')->id)->delete();
    Storage::fake(config('opal.packages_disk'));
    Storage::disk(config('opal.packages_disk'))->put($package->object_key, 'canonical package');
    $result = $this->getJson('/api/v1/library/variants/'.$variant->uuid.'/resolve?target=omniverse')
        ->assertOk()->assertJsonPath('data.format', 'canonical-usdz')->assertJsonPath('data.source_package_sha256', $package->sha256)->json('data');
    $this->get($result['files'][0]['url'])->assertOk()->assertStreamedContent('canonical package');
    $material->update(['visibility' => Visibility::Restricted]);
    $this->get($result['files'][0]['url'])->assertNotFound();
});

test('a consumer can revoke its own token but cannot delete another users token', function () {
    $own = $this->user->createToken('Omniverse');
    $foreign = User::factory()->create()->createToken('Other device');
    $this->withToken($foreign->plainTextToken)->deleteJson('/api/v1/account/token')->assertForbidden();
    expect($foreign->accessToken->fresh())->not->toBeNull();
    $this->withToken($own->plainTextToken)->deleteJson('/api/v1/account/token')->assertNoContent();
    expect($own->accessToken->fresh())->toBeNull();
});
