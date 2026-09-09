<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Actions\Materials\AddVariant;
use App\Actions\Representations\CreateRepresentation;
use App\Enums\Role;
use App\Library\FileStore;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\LibrarySeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake(config('opal.files_disk'));
    $this->seed(LibrarySeeder::class);
    app(SyncRolesAndPermissions::class)->handle();
    $this->tenant = Tenant::factory()->create();
    $this->viewer = User::factory()->withTenant($this->tenant, Role::Viewer)->create();
    $this->tenant->makeCurrent();
});

afterEach(fn () => Tenant::forgetCurrent());

test('signed-in users land on the library and guests see the cover page', function () {
    $this->get('/')->assertOk()->assertSee('Materials, with');
    $this->actingAs($this->viewer)->get('/')->assertRedirect(route('materials.index'));
});

test('the library uses consistent renders instead of raw maps and toggles to a table', function () {
    $plain = Material::factory()->create(['name' => 'Wool felt']);
    app(AddVariant::class)->handle($plain, ['colourway' => 'Dusk'], overrides: ['dominant_hex' => '#6b7a8c']);
    app(AddVariant::class)->handle($plain, ['colourway' => 'Dawn'], overrides: ['dominant_hex' => '#d8c7b0']);

    $pictured = Material::factory()->create(['name' => 'Travertine']);
    $variant = app(AddVariant::class)->handle($pictured, ['finish' => 'Honed']);
    $image = imagecreatetruecolor(8, 8);
    ob_start();
    imagepng($image);
    $raw = app(FileStore::class)->store((string) ob_get_clean(), 'travertine-base.png');
    app(CreateRepresentation::class)->handle($variant, 'pbr', '1k', ['base_color' => $raw]);

    $image = imagecreatetruecolor(8, 8);
    imagefilledrectangle($image, 0, 0, 7, 7, imagecolorallocate($image, 120, 100, 90));
    ob_start();
    imagepng($image);
    $render = app(FileStore::class)->store((string) ob_get_clean(), 'travertine-preview.png');
    app(CreateRepresentation::class)->handle($variant, 'preview', 'preview', ['render' => $render]);

    $page = Livewire::actingAs($this->viewer)
        ->test('pages::materials.index')
        ->assertSee('data-test="swatch-grid"', false)
        ->assertSee('--chip: #6b7a8c', false)
        ->assertSee('--chip: #d8c7b0', false)
        ->assertSee($render->url(), false)
        ->assertDontSee($raw->url(), false)
        ->assertSee('data-test="colourways"', false)
        ->assertSee('data-badge="pbr" data-state="candidate"', false)
        ->assertSee('data-badge="none"', false)
        ->assertSee('x-data="swatchCard(', false)
        ->assertSee('2 variants')
        ->call('setView', 'table')
        ->assertDontSee('data-test="swatch-grid"', false)
        ->assertSee('data-test="material-row"', false)
        ->assertSee('Travertine')
        ->call('setView', 'nonsense');

    expect($page->get('view'))->toBe('swatches');

    $this->actingAs($this->viewer)->get(route('materials.index', ['view' => 'table']))->assertOk()->assertSee('data-test="material-row"', false);
});

test('library files stream to signed-in users with immutable caching', function () {
    $file = app(FileStore::class)->store("hello\n", 'note.txt', 'text/plain');

    $this->get($file->url())->assertRedirect(route('login'));

    $this->actingAs($this->viewer)
        ->get($file->url())
        ->assertOk()
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, private')
        ->assertStreamedContent("hello\n");
});

test('the app shell carries the workspace switcher, primary navigation and user menu', function () {
    $this->actingAs($this->viewer)
        ->get(route('materials.index'))
        ->assertOk()
        ->assertSee('data-test="workspace-switcher"', false)
        ->assertSee('data-test="user-menu"', false)
        ->assertSee($this->tenant->name)
        ->assertSee(route('dashboard'))
        ->assertDontSee(route('drives.index'));
});

test('the material record carries the same interactive preview and target badges', function () {
    $material = Material::factory()->create(['name' => 'Wool felt']);
    app(AddVariant::class)->handle($material, ['colourway' => 'Dusk'], overrides: ['dominant_hex' => '#6b7a8c']);
    $dawn = app(AddVariant::class)->handle($material, ['colourway' => 'Dawn'], overrides: ['dominant_hex' => '#d8c7b0']);
    $image = imagecreatetruecolor(8, 8);
    ob_start();
    imagepng($image);
    $file = app(FileStore::class)->store((string) ob_get_clean(), 'dawn.png');
    app(CreateRepresentation::class)->handle($dawn, 'pbr', '1k', ['base_color' => $file]);

    Livewire::actingAs($this->viewer)
        ->test('pages::materials.show', ['material' => $material])
        ->assertSee('data-test="material-hero"', false)
        ->assertSee('data-test="colourways"', false)
        ->assertSee('--chip: #6b7a8c', false)
        ->assertSee($file->url(), false)
        ->assertSee('data-badge="pbr" data-state="candidate"', false)
        ->assertSee('x-on:mouseenter="preview(0)"', false)
        ->assertSee('applyInRevit(chosen.id)', false)
        ->assertSee('is-highlighted', false);
});
