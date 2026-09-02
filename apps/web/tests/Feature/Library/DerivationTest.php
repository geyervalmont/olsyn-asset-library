<?php

use App\Actions\Materials\AddVariant;
use App\Actions\Platforms\AssignPlatformIdentity;
use App\Actions\Representations\CreateRepresentation;
use App\Actions\Representations\DeriveFromPlatformReference;
use App\Actions\Representations\DeriveRepresentation;
use App\Actions\Representations\ReviewRepresentation;
use App\Enums\ReviewState;
use App\Library\FileStore;
use App\Models\Category;
use App\Models\File;
use App\Models\Material;
use App\Models\QualityTier;
use App\Models\Representation;
use App\Models\Supplier;
use App\Models\Target;
use App\Models\User;
use Database\Seeders\LibrarySeeder;
use Illuminate\Support\Facades\Storage;

function greyPng(int $grey, int $size = 4): string
{
    $image = imagecreatetruecolor($size, $size);
    imagefilledrectangle($image, 0, 0, $size, $size, imagecolorallocate($image, $grey, $grey, $grey));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

beforeEach(function () {
    Storage::fake(config('opal.files_disk'));
    $this->seed(LibrarySeeder::class);

    $carpet = Category::query()->where('code', 'CPT')->sole();
    $material = Material::factory()->create(['name' => 'Academix', 'category_id' => $carpet, 'supplier_id' => Supplier::factory()->create(['name' => 'Tarkett']), 'tile_width_mm' => 500, 'tile_height_mm' => 250]);
    $this->ashen = app(AddVariant::class)->handle($material, ['colourway' => 'Ashen']);
    $this->store = app(FileStore::class);

    $this->canonical = app(ReviewRepresentation::class)->handle(app(CreateRepresentation::class)->handle($this->ashen, 'pbr', '4k', [
        'base_color' => $this->store->store(greyPng(120), 'ashen_base_color.png'),
        'normal' => $this->store->store(greyPng(128), 'ashen_normal.png'),
        'roughness' => $this->store->store(greyPng(200), 'ashen_roughness.png'),
    ]), ReviewState::Approved);
});

test('an omniverse representation is derived from the canonical set with an MDL module and provenance', function () {
    $harrison = User::factory()->create(['name' => 'Harrison']);

    $derived = app(DeriveRepresentation::class)->handle($this->ashen, 'omniverse', '4k', $harrison);

    $mdl = $derived->fileFor('mdl');
    $text = $mdl?->contents() ?? '';

    expect($derived->target->slug)->toBe('omniverse')
        ->and($derived->kind)->toBe(Representation::KIND_PACKAGE)
        ->and($derived->review_state)->toBe(ReviewState::Candidate)
        ->and($derived->metadata['derived_from_representation_id'] ?? null)->toBe($this->canonical->getKey())
        ->and(array_keys($derived->filesByRole()))->toBe(['base_color', 'normal', 'roughness', 'mdl'])
        ->and($derived->fileFor('base_color')?->is($this->canonical->fileFor('base_color')))->toBeTrue()
        ->and($mdl?->mime_type)->toBe('text/x-mdl')
        ->and($mdl?->original_name)->toBe('CPT-TARKETT-ACADEMIX-ASHEN.mdl')
        ->and($text)->toContain('export material CPT_TARKETT_ACADEMIX_ASHEN(*)')
        ->and($text)->toContain('anno::display_name("Ashen [CPT-TARKETT-ACADEMIX-ASHEN]")')
        ->and($text)->toContain('diffuse_texture: texture_2d("./'.basename($this->canonical->fileFor('base_color')?->object_key ?? '').'", ::tex::gamma_srgb)')
        ->and($text)->toContain('normalmap_texture')
        ->and($text)->toContain('texture_scale: float2(2, 4)');

    $event = $derived->provenanceEvents()->sole();

    expect($event->action)->toBe('converted')
        ->and($event->actor?->name)->toBe('Harrison')
        ->and($event->tool)->toBe('opal omniverse mdl converter')
        ->and($event->inputs()->count())->toBe(3)
        ->and($event->outputs()->count())->toBe(4)
        ->and($mdl?->lineage()->first()?->is($event))->toBeTrue()
        ->and($mdl?->ancestors()->count())->toBe(3);
});

test('a revit image set inverts roughness into glossiness and keeps the normal as bump', function () {
    $derived = app(DeriveRepresentation::class)->handle($this->ashen, 'revit', '2k', 'App\\Jobs\\DeriveTarget');
    $gloss = $derived->fileFor('glossiness');
    $image = imagecreatefromstring($gloss?->contents() ?? '');
    $pixel = $image === false ? null : imagecolorsforindex($image, imagecolorat($image, 1, 1));

    expect(array_keys($derived->filesByRole()))->toBe(['base_color', 'glossiness', 'bump'])
        ->and($derived->fileFor('bump')?->is($this->canonical->fileFor('normal')))->toBeTrue()
        ->and($gloss?->kind)->toBe('image')
        ->and($pixel['red'] ?? null)->toBe(255 - 200)
        ->and($derived->metadata['derived_from_quality'] ?? null)->toBe('4k')
        ->and($derived->provenanceEvents()->sole()->actor_name)->toBe('App\\Jobs\\DeriveTarget');
});

test('the omniverse material for a revit material is one call', function () {
    app(AssignPlatformIdentity::class)->handle($this->ashen, 'revit', 'rvt-guid-1', 'Carpet - Academix Ashen');

    $byId = app(DeriveFromPlatformReference::class)->handle('revit', 'rvt-guid-1', 'omniverse', '2k');
    $byCode = app(DeriveFromPlatformReference::class)->handle('revit', 'Anything [CPT-TARKETT-ACADEMIX-ASHEN]', 'omniverse', '2k');

    expect($byId->variant->is($this->ashen))->toBeTrue()
        ->and($byCode->variant->is($this->ashen))->toBeTrue()
        ->and($byCode->fileFor('mdl')?->is($byId->fileFor('mdl')))->toBeTrue()
        ->and(File::query()->where('mime_type', 'text/x-mdl')->count())->toBe(1);

    expect(fn () => app(DeriveFromPlatformReference::class)->handle('revit', 'unknown', 'omniverse', '2k'))->toThrow(LogicException::class);
});

test('derivation falls back to the closest approved canonical quality and refuses without one', function () {
    $derive = app(DeriveRepresentation::class);

    expect($derive->canonicalFor($this->ashen, QualityTier::fromSlug('1k'))->is($this->canonical))->toBeTrue()
        ->and($derive->canonicalFor($this->ashen, QualityTier::fromSlug('8k'))->is($this->canonical))->toBeTrue();

    $twoK = app(ReviewRepresentation::class)->handle(app(CreateRepresentation::class)->handle($this->ashen, 'pbr', '2k', [
        'base_color' => $this->store->store(greyPng(90), 'ashen_base_color_2k.png'),
    ]), ReviewState::Approved);

    expect($derive->canonicalFor($this->ashen, QualityTier::fromSlug('1k'))->is($twoK))->toBeTrue()
        ->and($derive->canonicalFor($this->ashen, QualityTier::fromSlug('4k'))->is($this->canonical))->toBeTrue();

    $bare = app(AddVariant::class)->handle($this->ashen->material, ['colourway' => 'Slate']);

    expect(fn () => $derive->handle($bare, 'omniverse', '2k'))->toThrow(LogicException::class);
    expect(fn () => $derive->handle($this->ashen, Target::query()->create(['slug' => 'unreal', 'name' => 'Unreal']), '2k'))->toThrow(RuntimeException::class);
});
