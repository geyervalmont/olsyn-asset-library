<?php

use App\Enums\MaterialStatus;
use App\Enums\ReviewState;
use App\Library\Legacy\LegacyImporter;
use App\Models\Alias;
use App\Models\Material;
use App\Models\ProvenanceEvent;
use App\Models\Variant;
use Database\Seeders\LibrarySeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('opal.files_disk'));
    $this->seed(LibrarySeeder::class);
    $this->dir = sys_get_temp_dir().'/opal-legacy-'.uniqid();
    mkdir($this->dir);
    legacyFixture($this->dir);
});

test('the legacy library imports products, variants, files and provenance, and is idempotent', function () {
    $importer = app(LegacyImporter::class);
    $importer->run($this->dir.'/legacy.sqlite', $this->dir.'/files');

    expect($importer->errors)->toBe([])
        ->and($importer->stats['materials_created'])->toBe(3)
        ->and($importer->stats['variants'])->toBe(5)
        ->and($importer->stats['variants_merged'])->toBe(1)
        ->and($importer->stats['files'])->toBe(4)
        ->and($importer->stats['files_missing'])->toBe(1)
        ->and($importer->stats['representations'])->toBe(2);

    $academix = Material::resolveCode('CPT-TARKETT-ACADEMIX');
    $ashen = Variant::resolveCode('carpet:tarkett:academix:634014001:ashen');

    expect($ashen?->name)->toBe('Ashen')
        ->and($ashen?->attributes()->where('supplier_code', '001')->exists())->toBeTrue();
    $slate = Variant::resolveCode('CARPET:TARKETT:ACADEMIX:634014002:SLATE');

    expect($academix?->supplier?->name)->toBe('Tarkett')
        ->and($academix?->supplier_product_code)->toBe('634014')
        ->and($academix?->material_type)->toBe('carpet tile')
        ->and((float) $academix?->tile_width_mm)->toBe(500.0)
        ->and((float) $academix?->thickness_mm)->toBe(6.5)
        ->and((float) $academix?->sqm_cost)->toBe(42.5)
        ->and($academix?->install_pattern)->toBe('Monolithic')
        ->and($academix?->specifications['legacy']['product_id'] ?? null)->toBe('p1')
        ->and($academix?->tags()->pluck('name')->sort()->values()->all())->toBe(['commercial', 'loop pile'])
        ->and($academix?->source?->slug)->toBe('tarkett-website')
        ->and($academix?->provenanceEvents()->sole()->action)->toBe('imported')
        ->and($ashen?->code)->toBe('CPT-TARKETT-ACADEMIX-ASHEN')
        ->and($ashen?->attributeValue('colourway'))->toBe('Ashen')
        ->and($ashen?->dominant_hex)->toBe('#8a8a86')
        ->and($ashen?->colour_family)->toBe('grey')
        ->and($ashen?->tile_width_mm)->toBeNull()
        ->and($slate?->attributeValue('finish'))->toBe('Matte')
        ->and($slate?->attributeValue('pattern'))->toBeNull()
        ->and((float) $slate?->tile_width_mm)->toBe(250.0)
        ->and($slate?->dominant_hex)->toBeNull();

    $revit = $ashen?->representations()->whereRelation('target', 'slug', 'revit')->sole();
    $pbr = $ashen?->representations()->whereRelation('target', 'slug', 'pbr')->sole();

    expect(array_keys($revit?->filesByRole() ?? []))->toBe(['base_color', 'ref_image'])
        ->and($revit?->review_state)->toBe(ReviewState::Approved)
        ->and($revit?->quality->pixels)->toBe(64)
        ->and($pbr?->review_state)->toBe(ReviewState::Candidate)
        ->and(array_keys($pbr?->filesByRole() ?? []))->toBe(['base_color', 'normal'])
        ->and($pbr?->provenanceEvents()->sole()->tool)->toBe('codex_seamless_material_worker')
        ->and($pbr?->provenanceEvents()->sole()->source?->slug)->toBe(LegacyImporter::SOURCE_SLUG)
        ->and($revit?->provenanceEvents()->sole()->source_url)->toBe('https://tarkett.example/ashen.jpg')
        ->and($revit?->fileFor('base_color')?->sources()->pluck('slug')->all())->toBe(['tarkett-website']);

    $allura = Material::resolveCode('VNL-FORBO-ALLURA');
    $old = Material::query()->where('name', 'Old Vinyl')->sole();
    $oak = $allura?->variants->first();

    $duckegg = Variant::resolveCode('vinyl_flooring:forbo:allura:na:duckegg_nrm');

    expect($allura?->category->code)->toBe('VNL')
        ->and($oak?->name)->toBe('Oak')
        ->and($allura?->variants()->count())->toBe(2)
        ->and($duckegg?->name)->toBe('Duckegg')
        ->and($duckegg?->is(Variant::resolveCode('vinyl_flooring:forbo:allura:na:duckegg_base')))->toBeTrue()
        ->and($duckegg?->aliases()->count())->toBe(2)
        ->and($oak?->attributeValue('pattern'))->toBe('Herringbone')
        ->and($oak?->repeat_type)->toBe('surface_crop')
        ->and($old->category->code)->toBe('UNC')
        ->and($old->status)->toBe(MaterialStatus::Archived);

    $importer->run($this->dir.'/legacy.sqlite', $this->dir.'/files');

    expect($importer->stats['materials_existing'])->toBe(3)
        ->and(Material::query()->count())->toBe(3)
        ->and(Variant::query()->count())->toBe(5)
        ->and($ashen?->representations()->count())->toBe(2);

    $this->artisan('opal:import:legacy', ['--database' => $this->dir.'/nope.sqlite', '--files' => 'none'])
        ->assertFailed();

    expect($importer->forget())->toBe(3)
        ->and(Material::query()->count())->toBe(0)
        ->and(Variant::query()->count())->toBe(0)
        ->and(Alias::query()->count())->toBe(0)
        ->and(ProvenanceEvent::query()->count())->toBe(0);
});
