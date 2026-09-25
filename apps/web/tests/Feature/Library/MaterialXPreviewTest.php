<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Actions\Materials\AddVariant;
use App\Enums\Role;
use App\Enums\Visibility;
use App\Library\Previews\MaterialPreviews;
use App\Library\Previews\MaterialXPreview;
use App\Models\Material;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\LibrarySeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('opal.packages_disk'));
    $this->seed(LibrarySeeder::class);
    app(SyncRolesAndPermissions::class)->handle();
    $this->tenant = Tenant::factory()->create();
    $this->viewer = User::factory()->withTenant($this->tenant, Role::Viewer)->create();
    $this->tenant->makeCurrent();
    $this->material = Material::factory()->create();
    $this->variant = app(AddVariant::class)->handle($this->material, ['colourway' => 'Red']);
    $bytes = 'canonical package '.bin2hex(random_bytes(8));
    $this->package = Package::factory()->for($this->variant)->create(['sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)]);
    Storage::disk(config('opal.packages_disk'))->put($this->package->object_key, $bytes);
    $this->url = route('packages.materialx-preview', $this->package);
    $this->binary = tempnam(sys_get_temp_dir(), 'materialx-toolbox-');
    file_put_contents($this->binary, <<<'PHP'
#!/usr/bin/env php
<?php
if ($argv[1] !== 'export' || $argv[array_search('--target', $argv) + 1] !== 'materialx-preview' || $argv[array_search('--tier', $argv) + 1] !== 'preview') { exit(2); }
file_put_contents($argv[array_search('--output', $argv) + 1], json_encode(['schema' => 'usd-toolbox.materialx-preview.v1', 'document' => '<materialx version="1.39"/>', 'material_names' => ['Red'], 'assets' => (object) [], 'losses' => []]));
PHP);
    chmod($this->binary, 0755);
    config(['opal.toolbox_bin' => $this->binary]);
});

afterEach(function () {
    @unlink($this->binary);
    Cache::store('file')->forget(app(MaterialXPreview::class)->key($this->package));
    Tenant::forgetCurrent();
});

test('materialx previews require access and cache verified package conversions', function () {
    $this->get($this->url)->assertRedirect(route('login'));
    $response = $this->actingAs($this->viewer)->get($this->url)->assertOk()
        ->assertJsonPath('schema', 'usd-toolbox.materialx-preview.v1')
        ->assertHeader('Cache-Control', 'must-revalidate, no-cache, private');
    Storage::disk(config('opal.packages_disk'))->delete($this->package->object_key);
    $this->get($this->url)->assertOk()->assertContent($response->getContent());
    $this->withHeader('If-None-Match', $response->headers->get('ETag'))->get($this->url)->assertStatus(304);
    $this->material->update(['visibility' => Visibility::Restricted]);
    $this->get($this->url)->assertNotFound();
});

test('a damaged package cannot be converted or cached as a preview', function () {
    Storage::disk(config('opal.packages_disk'))->put($this->package->object_key, 'tampered');
    $this->actingAs($this->viewer)->get($this->url)->assertStatus(503)->assertHeader('Cache-Control', 'no-store, private');
    expect(Cache::store('file')->has(app(MaterialXPreview::class)->key($this->package)))->toBeFalse();
});

test('a packaged material can be previewed without loose authoring maps', function () {
    $sets = app(MaterialPreviews::class)->viewerSets($this->material, collect([$this->variant->load('representations')]));
    expect($sets[$this->variant->id]['materialx_url'])->toBe($this->url);
});
