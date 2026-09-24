<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Actions\Drives\ManageIntake;
use App\Actions\Materials\AddVariant;
use App\Actions\Versions\PublishVersion;
use App\Enums\Role;
use App\Enums\VersionStatus;
use App\Enums\Visibility;
use App\Library\Drives\DriveNamespace;
use App\Library\Drives\DrivePath;
use App\Models\DriveConnection;
use App\Models\File;
use App\Models\MapRole;
use App\Models\Material;
use App\Models\Package;
use App\Models\PackageDerivative;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\LibrarySeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed(LibrarySeeder::class);
    app(SyncRolesAndPermissions::class)->handle();
    $this->tenant = Tenant::factory()->create();
    $this->tenant->makeCurrent();
    $this->user = User::factory()->withTenant($this->tenant, Role::Editor)->create();
    $this->other = User::factory()->withTenant($this->tenant, Role::Editor)->create();
    Storage::fake(config('opal.files_disk'));
    Storage::fake(config('opal.drive.intake_disk'));
    Sanctum::actingAs($this->user, ['*']);
});

test('the personal drive uses user grants and never exposes storage credentials or unpublished files', function () {
    $material = Material::factory()->create(['visibility' => Visibility::Restricted]);
    $variant = app(AddVariant::class)->handle($material, ['colourway' => 'Test']);
    $package = Package::factory()->for($variant)->create();
    $derivative = PackageDerivative::factory()->for($package)->create();
    $file = File::factory()->create(['disk' => config('opal.files_disk'), 'bytes' => 10, 'sha256' => hash('sha256', '0123456789')]);
    Storage::disk($file->disk)->put($file->object_key, '0123456789');
    $derivative->derivativeFiles()->create(['file_id' => $file->id, 'map_role_id' => MapRole::fromSlug('base_color')->id]);
    $version = $material->versions()->create(['number' => 1, 'status' => VersionStatus::Draft]);
    $version->packages()->attach($package->id, ['variant_id' => $variant->id]);
    $url = '/api/v1/drive/files/'.$derivative->uuid.'/'.$file->id;
    $this->getJson('/api/v1/drive/manifest')->assertOk()->assertJsonCount(0, 'files');
    $this->get($url)->assertNotFound();
    app(PublishVersion::class)->handle($version);
    $this->get($url)->assertNotFound();
    $material->grants()->create(['grantee_type' => $this->user->getMorphClass(), 'grantee_id' => $this->user->id]);
    $response = $this->getJson('/api/v1/drive/manifest')->assertOk()->assertJsonCount(4, 'files');
    expect($response->json('files.0'))->toHaveKeys(['content_url', 'material_uuid', 'sha256'])
        ->not->toHaveKeys(['object', 'bucket', 'disk', 'key']);
    $this->withHeader('If-None-Match', $response->headers->get('ETag'))->getJson('/api/v1/drive/manifest')->assertStatus(304);
    $this->flushHeaders();
    $this->get($url, ['Range' => 'bytes=2-5'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 2-5/10')->assertStreamedContent('2345');
    $this->get($url, ['Range' => 'bytes=-3'])->assertStatus(206)->assertStreamedContent('789');
    $this->get($url, ['Range' => 'bytes=8-'])->assertStatus(206)->assertStreamedContent('89');
    $this->get($url, ['Range' => 'bytes=20-30'])->assertStatus(416)->assertHeader('Content-Range', 'bytes */10');
    $this->get($url, ['Range' => 'bytes=0-1,3-4'])->assertStatus(416);
    $this->get($url, ['Range' => 'bytes=2-3', 'If-Range' => '"old"'])->assertOk()->assertStreamedContent('0123456789');
    $this->head($url)->assertOk()->assertHeader('Content-Length', '10');
    $material->grants()->delete();
    $this->get($url, ['If-None-Match' => '"'.$file->sha256.'"'])->assertNotFound();
    $this->getJson('/api/v1/drive/manifest')->assertJsonCount(0, 'files');
});

test('intake is private, bounded, checksum verified, retryable and never starts ingestion', function () {
    $created = $this->postJson('/api/v1/drive/intake', ['name' => 'Stone collection'])->assertCreated()->json('data');
    $root = '/api/v1/drive/intake/'.$created['id'];
    $this->getJson('/api/v1/drive/manifest')->assertJsonPath('incoming.0.id', $created['id']);
    $metadata = ['path' => 'stone/base_color.png', 'bytes' => 4, 'sha256' => hash('sha256', 'data')];
    $entry = $this->postJson($root.'/files', $metadata)->assertCreated()->json('data');
    $this->postJson($root.'/files', $metadata)->assertCreated()->assertJsonPath('data.id', $entry['id']);
    $this->postJson($root.'/files', array_replace($metadata, ['sha256' => hash('sha256', 'oops')]))->assertStatus(409);
    $this->postJson($root.'/submit')->assertStatus(409);
    $this->call('PUT', $entry['upload_url'], server: ['CONTENT_TYPE' => 'application/octet-stream'], content: 'oops')->assertUnprocessable();
    $this->call('PUT', $entry['upload_url'], server: ['CONTENT_TYPE' => 'application/octet-stream'], content: 'data')->assertNoContent();
    $this->call('PUT', $entry['upload_url'], server: ['CONTENT_TYPE' => 'application/octet-stream'], content: 'data')->assertNoContent();
    $this->getJson($root)->assertJsonPath('data.files.0.uploaded', true)->assertJsonPath('data.processing_enabled', false);
    Sanctum::actingAs($this->other, ['*']);
    $this->getJson($root)->assertNotFound();
    $this->getJson('/api/v1/drive/manifest')->assertJsonCount(0, 'incoming');
    $this->postJson($root.'/files', $metadata)->assertNotFound();
    Sanctum::actingAs($this->user, ['*']);
    $this->postJson($root.'/submit')->assertAccepted()->assertJsonPath('data.status', 'submitted');
    $this->postJson($root.'/submit')->assertAccepted();
    $this->getJson('/api/v1/drive/manifest')->assertJsonCount(0, 'incoming');
    $this->postJson($root.'/files', $metadata)->assertStatus(409);
    expect(Material::count())->toBe(0);
});

test('intake rejects unsafe Windows paths and expired or revoked write access', function () {
    $session = app(ManageIntake::class)->create($this->user, 'Private drop');
    $root = '/api/v1/drive/intake/'.$session->uuid;
    foreach (['../secret', '/absolute', 'C:/data', 'a\\b', 'CON.png', 'test.', 'dir//file'] as $path) {
        $this->postJson($root.'/files', ['path' => $path, 'bytes' => 4, 'sha256' => hash('sha256', 'data')])->assertUnprocessable();
    }
    $this->travel(25)->hours();
    $this->getJson($root)->assertJsonPath('data.status', 'expired');
    $this->postJson($root.'/files', ['path' => 'file.png', 'bytes' => 4, 'sha256' => hash('sha256', 'data')])->assertStatus(409);
    $this->getJson('/api/v1/drive/manifest')->assertJsonCount(0, 'incoming');
    $viewer = User::factory()->withTenant($this->tenant, Role::Viewer)->create();
    Sanctum::actingAs($viewer, ['*']);
    $this->postJson('/api/v1/drive/intake', ['name' => 'No permission'])->assertForbidden();
    $this->getJson('/api/v1/drive/bootstrap')->assertJsonPath('data.capabilities.intake', false);
    Sanctum::actingAs($this->user, ['drive:read']);
    $this->postJson('/api/v1/drive/intake', ['name' => 'Read-only token'])->assertForbidden();
    $this->getJson('/api/v1/materials')->assertForbidden();
});

test('intake enforces directory collisions, batch limits and cancellation', function () {
    config(['opal.drive.intake_active_sessions' => 1, 'opal.drive.intake_max_files' => 2, 'opal.drive.intake_batch_bytes' => 8]);
    $session = $this->postJson('/api/v1/drive/intake', ['name' => 'Batch'])->assertCreated()->json('data.id');
    $root = '/api/v1/drive/intake/'.$session;
    $this->postJson('/api/v1/drive/intake', ['name' => 'Overflow'])->assertStatus(409);
    $metadata = ['path' => 'Stone/base.png', 'bytes' => 4, 'sha256' => hash('sha256', 'data')];
    $entry = $this->postJson($root.'/files', $metadata)->assertCreated()->json('data');
    foreach (['stone', 'STONE/base.png/subfile'] as $path) {
        $this->postJson($root.'/files', array_replace($metadata, ['path' => $path]))->assertStatus(409);
    }
    $this->postJson($root.'/files', array_replace($metadata, ['path' => 'STOne/BASE.PNG']))->assertCreated()->assertJsonPath('data.id', $entry['id']);
    $this->postJson($root.'/files', array_replace($metadata, ['path' => 'roughness.png', 'bytes' => 5]))->assertUnprocessable();
    $this->postJson($root.'/files', array_replace($metadata, ['path' => 'roughness.png']))->assertCreated();
    $this->postJson($root.'/files', array_replace($metadata, ['path' => 'third.png']))->assertUnprocessable();
    $this->deleteJson($root)->assertNoContent();
    $this->call('PUT', $entry['upload_url'], server: ['CONTENT_TYPE' => 'application/octet-stream'], content: 'data')->assertStatus(409);
    $this->postJson($root.'/submit')->assertStatus(409);
    $this->getJson('/api/v1/drive/manifest')->assertJsonCount(0, 'incoming');
    $this->postJson('/api/v1/drive/intake', ['name' => 'Replacement'])->assertCreated();
});

test('a real scoped device token can report its own drive and stops working when revoked', function () {
    $token = $this->user->createToken('Drive', ['drive:read', 'drive:write']);
    $this->app['auth']->forgetGuards();
    $this->withToken($token->plainTextToken);
    $data = ['device_id' => (string) Str::uuid(), 'machine' => 'DESIGN-01', 'state' => 'mounted', 'mount_path' => 'O:\\'];
    $this->postJson('/api/v1/drive/heartbeat', array_diff_key($data, ['mount_path' => true]))->assertUnprocessable();
    $this->postJson('/api/v1/drive/heartbeat', $data)->assertOk();
    $this->postJson('/api/v1/drive/heartbeat', $data)->assertOk();
    $connection = DriveConnection::sole();
    expect($connection->user_id)->toBe($this->user->id)->and($connection->isLive())->toBeTrue();
    $this->getJson('/api/v1/materials')->assertForbidden();
    $token->accessToken->delete();
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/drive/manifest')->assertUnauthorized();
    expect($connection->isLive())->toBeFalse();
});

test('legacy browser file URLs cannot bypass personal-drive material visibility', function () {
    $material = Material::factory()->create(['visibility' => Visibility::Restricted]);
    $variant = app(AddVariant::class)->handle($material, ['colourway' => 'Private']);
    $package = Package::factory()->for($variant)->create();
    $derivative = PackageDerivative::factory()->for($package)->create();
    $file = File::factory()->create(['disk' => config('opal.files_disk'), 'bytes' => 4]);
    Storage::disk($file->disk)->put($file->object_key, 'data');
    $derivative->derivativeFiles()->create(['file_id' => $file->id, 'map_role_id' => MapRole::fromSlug('base_color')->id]);
    $this->actingAs($this->user, 'web')->get($file->url())->assertNotFound();
    $material->grants()->create(['grantee_type' => $this->user->getMorphClass(), 'grantee_id' => $this->user->id]);
    $this->get($file->url())->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertStreamedContent('data');
    $material->grants()->delete();
    $this->get($file->url())->assertNotFound();
    $orphan = File::factory()->create();
    $this->get($orphan->url())->assertNotFound();
});

test('drive-scoped tokens can revoke themselves without gaining consumer API access', function () {
    $token = $this->user->createToken('Drive', ['drive:read', 'drive:write']);
    $this->app['auth']->forgetGuards();
    $this->withToken($token->plainTextToken);
    $this->getJson('/api/v1/library')->assertForbidden();
    $this->deleteJson('/api/v1/account/token')->assertNoContent();
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/drive/bootstrap')->assertUnauthorized();
});

test('canonical USDZ drive paths retain identity and enforce grants on range and HEAD reads', function () {
    Storage::fake(config('opal.packages_disk'));
    $material = Material::factory()->create(['visibility' => Visibility::Restricted]);
    $variant = app(AddVariant::class)->handle($material, ['colourway' => 'Full resolution']);
    $package = Package::factory()->for($variant)->create(['bytes' => 10, 'sha256' => hash('sha256', '0123456789')]);
    Storage::disk(config('opal.packages_disk'))->put($package->object_key, '0123456789');
    PackageDerivative::factory()->for($package)->create();
    $version = $material->versions()->create(['number' => 1, 'status' => VersionStatus::Draft]);
    $version->packages()->attach($package->id, ['variant_id' => $variant->id]);
    $url = '/api/v1/drive/packages/'.$package->id;
    $this->get($url)->assertNotFound();
    app(PublishVersion::class)->handle($version);
    $this->head($url)->assertNotFound();
    $material->grants()->create(['grantee_type' => $this->user->getMorphClass(), 'grantee_id' => $this->user->id]);
    $this->getJson('/api/v1/drive/manifest')->assertJsonPath('files.0.path', '/materials/by-id/'.$material->uuid.'/'.$variant->uuid.'/v1/canonical/'.$package->sha256.'.usdz')
        ->assertJsonPath('files.0.content_url', $url);
    $this->head($url)->assertOk()->assertHeader('Content-Length', '10')->assertHeader('ETag', '"'.$package->sha256.'"');
    $this->get($url, ['Range' => 'bytes=2-5'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 2-5/10')->assertStreamedContent('2345');
    $material->grants()->delete();
    $this->get($url, ['Range' => 'bytes=2-5'])->assertNotFound();
    $this->head($url)->assertNotFound();
});

test('by-name offers the latest published files while renames leave every by-id reference intact', function () {
    $material = Material::factory()->create(['name' => 'Limestone']);
    $variant = app(AddVariant::class)->handle($material, ['colourway' => 'Warm grey']);
    $package = Package::factory()->for($variant)->create();
    $old = PackageDerivative::factory()->for($package)->create();
    $file = File::factory()->create(['disk' => config('opal.files_disk'), 'bytes' => 10, 'extension' => 'png', 'sha256' => hash('sha256', '0123456789')]);
    Storage::disk($file->disk)->put($file->object_key, '0123456789');
    $old->derivativeFiles()->create(['file_id' => $file->id, 'map_role_id' => MapRole::fromSlug('base_color')->id]);
    $first = $material->versions()->create(['number' => 1, 'status' => VersionStatus::Draft]);
    $first->packages()->attach($package->id, ['variant_id' => $variant->id]);
    app(PublishVersion::class)->handle($first);
    $original = collect($this->getJson('/api/v1/drive/manifest')->assertOk()->json('files'))->filter(fn ($f) => str_contains($f['path'], '/by-id/'))->pluck('path')->all();
    $latest = PackageDerivative::factory()->for($package)->create(['converter_version' => '2.0.0', 'built_at' => now()->addMinute()]);
    $latest->derivativeFiles()->create(['file_id' => $file->id, 'map_role_id' => MapRole::fromSlug('base_color')->id]);
    $second = $material->versions()->create(['number' => 2, 'status' => VersionStatus::Draft]);
    $second->packages()->attach($package->id, ['variant_id' => $variant->id]);
    app(PublishVersion::class)->handle($second);
    $response = $this->getJson('/api/v1/drive/manifest')->assertOk();
    $named = collect($response->json('files'))->filter(fn ($f) => str_contains($f['path'], '/by-name/'))->values();
    expect($named)->toHaveCount(2)->and($named->pluck('material_version')->unique()->all())->toBe([2]);
    $texture = $named->firstWhere('role', 'base_color');
    expect($texture['path'])->toContain('/Limestone/Warm grey/revit/2k/base_color.png')
        ->and($texture['derivative_uuid'])->toBe($latest->uuid)->and($texture['sha256'])->toBe($file->sha256);
    expect($named->firstWhere('role', 'package')['path'])->toEndWith('/canonical/material.usdz');
    $this->get($texture['content_url'], ['Range' => 'bytes=2-5'])->assertStatus(206)->assertStreamedContent('2345');
    $material->update(['name' => 'Renamed limestone']);
    $variant->update(['name' => 'Renamed grey']);
    $this->withHeader('If-None-Match', $response->headers->get('ETag'));
    $renamed = collect($this->getJson('/api/v1/drive/manifest')->assertOk()->json('files'));
    expect($renamed->where('path', $texture['path']))->toHaveCount(0)
        ->and($renamed->filter(fn ($f) => str_contains($f['path'], '/Renamed limestone/Renamed grey/')))->toHaveCount(2)
        ->and(array_diff($original, $renamed->pluck('path')->all()))->toBe([]);
});

test('by-name handles reserved Windows names, punctuation, case collisions and long Unicode labels', function () {
    $one = Material::factory()->create(['name' => 'CON']);
    $two = Material::factory()->create(['name' => '_CON', 'category_id' => $one->category_id]);
    $a = app(AddVariant::class)->handle($one, ['colourway' => 'Sand:grey']);
    $b = app(AddVariant::class)->handle($one, ['colourway' => 'sand?grey']);
    $c = app(AddVariant::class)->handle($two, ['colourway' => 'Long']);
    $c->update(['name' => str_repeat('木', 80)]);
    $files = collect([$a, $b, $c])->map(fn ($variant) => [
        'path' => '/materials/by-id/test/base_color.png', 'material_uuid' => $variant->material->uuid,
        'variant_uuid' => $variant->uuid, 'target' => 'revit', 'quality' => '2k', 'role' => 'base_color',
    ])->all();
    $named = app(DriveNamespace::class)->namedEntriesForUser($this->user, $files);
    expect($named)->toHaveCount(3);
    $paths = array_column($named, 'path');
    expect(array_unique(array_map(fn ($p) => mb_convert_case($p, MB_CASE_FOLD, 'UTF-8'), $paths)))->toHaveCount(3);
    foreach ($paths as $path) {
        expect(DrivePath::validate(ltrim($path, '/')))->toBe(ltrim($path, '/'));
    }
    expect($paths[0])->toContain('['.$one->uuid.']')->toContain('['.$a->uuid.']');
    expect($paths[2])->toContain(str_repeat('木', 20));
});
