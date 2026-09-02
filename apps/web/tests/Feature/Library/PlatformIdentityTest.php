<?php

use App\Actions\Materials\AddVariant;
use App\Actions\Materials\RecodeMaterial;
use App\Actions\Platforms\AssignPlatformIdentity;
use App\Actions\Platforms\ResolvePlatformVariant;
use App\Models\Category;
use App\Models\Material;
use App\Models\Platform;
use App\Models\Supplier;
use Database\Seeders\LibrarySeeder;

beforeEach(function () {
    $this->seed(LibrarySeeder::class);

    $carpet = Category::query()->where('code', 'CPT')->sole();
    $this->material = Material::factory()->create(['name' => 'Academix', 'category_id' => $carpet, 'supplier_id' => Supplier::factory()->create(['name' => 'Tarkett'])]);
    $this->ashen = app(AddVariant::class)->handle($this->material, ['colourway' => 'Ashen']);
});

test('a variant resolves from a platform id, a platform name, or a code embedded in a name', function () {
    $assign = app(AssignPlatformIdentity::class);
    $resolve = app(ResolvePlatformVariant::class);

    $identity = $assign->handle($this->ashen, 'revit', '7c1e3a0d-0000-4b1c-9c2a-5f1e2d3c4b5a-000a1b2c', 'Carpet - Tarkett Academix Ashen', ['revit_material_category' => 'Carpet']);
    $assign->handle($this->ashen, 'revit', '7c1e3a0d-0000-4b1c-9c2a-5f1e2d3c4b5a-000a1b2c', 'Carpet - Tarkett Academix Ashen (renamed)');

    expect($this->ashen->platformIdentities()->count())->toBe(1)
        ->and($identity->fresh()?->external_name)->toBe('Carpet - Tarkett Academix Ashen (renamed)')
        ->and($identity->platform->target?->slug)->toBe('revit')
        ->and(Platform::fromSlug('enscape')->target?->slug)->toBe('revit')
        ->and($resolve->handle('revit', '7c1e3a0d-0000-4b1c-9c2a-5f1e2d3c4b5a-000a1b2c')?->is($this->ashen))->toBeTrue()
        ->and($resolve->handle('revit', 'Carpet - Tarkett Academix Ashen (renamed)')?->is($this->ashen))->toBeTrue()
        ->and($resolve->handle('revit', 'Academix Ashen [CPT-TARKETT-ACADEMIX-ASHEN]')?->is($this->ashen))->toBeTrue()
        ->and($resolve->handle('omniverse', 'cpt-tarkett-academix-ashen')?->is($this->ashen))->toBeTrue()
        ->and($resolve->handle('revit', 'Something else entirely'))->toBeNull()
        ->and($resolve->handle('revit', ''))->toBeNull();
});

test('old codes keep resolving after a recode', function () {
    $resolve = app(ResolvePlatformVariant::class);
    $oldCode = $this->ashen->code;

    app(RecodeMaterial::class)->handle($this->material, category: Category::query()->where('code', 'VNL')->sole());

    expect($this->ashen->fresh()?->code)->toBe('VNL-TARKETT-ACADEMIX-ASHEN')
        ->and($resolve->handle('revit', "Revit material [{$oldCode}]")?->is($this->ashen))->toBeTrue();
});
