<?php

use App\Actions\Materials\AddVariant;
use App\Actions\Representations\CreateRepresentation;
use App\Actions\Representations\ReviewRepresentation;
use App\Enums\ReviewState;
use App\Library\FileStore;
use App\Models\Category;
use App\Models\Material;
use App\Models\Supplier;
use Database\Seeders\LibrarySeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('opal.files_disk'));
    $this->seed(LibrarySeeder::class);
    $carpet = Category::query()->where('code', 'CPT')->sole();
    $this->material = Material::factory()->create(['name' => 'Academix', 'category_id' => $carpet, 'supplier_id' => Supplier::factory()->create(['name' => 'Tarkett'])]);
    $variant = app(AddVariant::class)->handle($this->material, ['colourway' => ['value' => 'Ashen']]);
    $image = imagecreatetruecolor(4, 4);
    ob_start();
    imagepng($image);
    $file = app(FileStore::class)->store((string) ob_get_clean(), 'base.png');
    $this->representation = app(CreateRepresentation::class)->handle($variant, 'pbr', '1k', ['base_color' => $file]);
});

test('the publish command cuts and publishes materials with approved representations', function () {
    // Nothing approved yet: nothing to publish.
    $this->artisan('opal:versions:publish')->expectsOutputToContain('Published 0')->assertSuccessful();

    app(ReviewRepresentation::class)->handle($this->representation, ReviewState::Approved);

    $this->artisan('opal:versions:publish', ['--dry-run' => true])->expectsOutputToContain('Would publish 1')->assertSuccessful();
    expect($this->material->fresh()->current_version_id)->toBeNull();

    $this->artisan('opal:versions:publish')->expectsOutputToContain($this->material->code)->assertSuccessful();
    expect($this->material->fresh()->current_version_id)->not->toBeNull();

    // Already published: left alone without --all.
    $this->artisan('opal:versions:publish')->expectsOutputToContain('Published 0')->assertSuccessful();
    $this->artisan('opal:versions:publish', ['--material' => 'nope'])->assertFailed();
});
