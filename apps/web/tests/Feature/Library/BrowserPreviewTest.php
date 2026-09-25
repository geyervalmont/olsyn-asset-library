<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Actions\Materials\AddVariant;
use App\Actions\Representations\CreateRepresentation;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Library\FileStore;
use App\Library\Previews\BrowserPreview;
use App\Models\FileAccess;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\LibrarySeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('opal.files_disk'));
    $this->seed(LibrarySeeder::class);
    app(SyncRolesAndPermissions::class)->handle();
    $this->tenant = Tenant::factory()->create();
    $this->viewer = User::factory()->withTenant($this->tenant, Role::Viewer)->create();
    $this->tenant->makeCurrent();
    $this->material = Material::factory()->create();
    $variant = app(AddVariant::class)->handle($this->material, ['colourway' => 'Stone']);
    $image = imagecreatetruecolor(1200, 600);
    imagefilledrectangle($image, 0, 0, 1199, 599, imagecolorallocate($image, 120, 110, 100));
    ob_start();
    imagepng($image);
    $this->original = (string) ob_get_clean();
    $this->file = app(FileStore::class)->store($this->original, 'preview.png');
    app(CreateRepresentation::class)->handle($variant, 'preview', 'preview', ['render' => $this->file]);
    foreach ([96, 512, 1024] as $size) {
        Cache::store('file')->forget(app(BrowserPreview::class)->key($this->file, $size));
    }
});

afterEach(fn () => Tenant::forgetCurrent());

test('browser previews are bounded webp images reused without reading the original again', function () {
    $this->get($this->file->previewUrl())->assertRedirect(route('login'));
    $response = $this->actingAs($this->viewer)->get($this->file->previewUrl())
        ->assertOk()->assertHeader('Content-Type', 'image/webp')
        ->assertHeader('Cache-Control', 'must-revalidate, no-cache, private');
    $image = getimagesizefromstring($response->getContent());
    expect($image[0])->toBe(512)->and($image[1])->toBe(256)
        ->and($image[2])->toBe(IMAGETYPE_WEBP)
        ->and($this->file->contents())->toBe($this->original)
        ->and(FileAccess::query()->latest('id')->first()->bytes)->toBe(strlen($response->getContent()));

    Storage::disk($this->file->disk)->delete($this->file->object_key);
    $this->get($this->file->previewUrl())->assertOk()->assertContent($response->getContent());
    $this->withHeader('If-None-Match', $response->headers->get('ETag'))
        ->get($this->file->previewUrl())->assertStatus(304)->assertContent('');
});

test('cached thumbnails recheck grants before returning bytes or a not-modified response', function () {
    $response = $this->actingAs($this->viewer)->get($this->file->previewUrl())->assertOk();
    $this->material->update(['visibility' => Visibility::Restricted]);
    $this->get($this->file->previewUrl())->assertNotFound();
    $this->withHeader('If-None-Match', $response->headers->get('ETag'))
        ->get($this->file->previewUrl())->assertNotFound();
    $this->get($this->file->url())->assertNotFound();
});

test('preview sizes are allowlisted and small images are never upscaled', function () {
    $this->actingAs($this->viewer)->get($this->file->previewUrl(99999))->assertNotFound();
    $response = $this->get($this->file->previewUrl(96))->assertOk();
    expect(getimagesizefromstring($response->getContent())[0])->toBe(96);

    $image = imagecreatetruecolor(16, 8);
    ob_start();
    imagepng($image);
    $small = app(FileStore::class)->store((string) ob_get_clean(), 'small.png');
    app(CreateRepresentation::class)->handle($this->material->variants()->first(), 'preview', 'preview', ['render' => $small]);
    $response = $this->get($small->previewUrl())->assertOk();
    expect(getimagesizefromstring($response->getContent())[0])->toBe(16);
});

test('large texture maps are resized with bounded image memory and keep their aspect ratio', function () {
    $image = imagecreatetruecolor(8192, 2049);
    ob_start();
    imagepng($image);
    unset($image);
    $large = app(FileStore::class)->store((string) ob_get_clean(), 'large.png');
    app(CreateRepresentation::class)->handle($this->material->variants()->first(), 'pbr', '8k', ['normal' => $large]);
    Cache::store('file')->forget(app(BrowserPreview::class)->key($large, 1024));
    $memory = Imagick::getResourceLimit(Imagick::RESOURCETYPE_MEMORY);
    $response = $this->actingAs($this->viewer)->get($large->previewUrl(1024))->assertOk();
    $info = getimagesizefromstring($response->getContent());
    expect($info[0])->toBe(1024)->and($info[1])->toBe(256)->and($info[2])->toBe(IMAGETYPE_WEBP)
        ->and(Imagick::getResourceLimit(Imagick::RESOURCETYPE_MEMORY))->toBe($memory);
});
