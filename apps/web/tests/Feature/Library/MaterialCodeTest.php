<?php

use App\Actions\Materials\AddVariant;
use App\Actions\Materials\CreateMaterial;
use App\Actions\Materials\RecodeMaterial;
use App\Library\CodeTokenizer;
use App\Models\Category;
use App\Models\Material;
use App\Models\Supplier;
use App\Models\Variant;
use Database\Seeders\LibrarySeeder;

beforeEach(function () {
    $this->seed(LibrarySeeder::class);
});

test('tokens are uppercase, ascii, underscore-joined and capped', function () {
    expect(CodeTokenizer::token('H Collection'))->toBe('H_COLLECTION')
        ->and(CodeTokenizer::token('Ashen, muted'))->toBe('ASHEN_MUTED')
        ->and(CodeTokenizer::token('Café Crème'))->toBe('CAFE_CREME')
        ->and(CodeTokenizer::token('  ---  '))->toBe('X')
        ->and(CodeTokenizer::token('Extraordinarily long product name', 12))->toBe('EXTRAORDINAR');
});

test('a material code reads as category, supplier and product', function () {
    $carpet = Category::query()->where('code', 'CPT')->sole();
    $tarkett = Supplier::factory()->create(['name' => 'Tarkett']);

    $material = app(CreateMaterial::class)->handle(
        ['name' => 'Academix', 'category_id' => $carpet->getKey(), 'supplier_id' => $tarkett->getKey()],
        [['attributes' => ['colourway' => ['value' => 'Ashen', 'supplier_code' => '634014001']]]],
    );

    expect($material->code)->toBe('CPT-TARKETT-ACADEMIX')
        ->and($material->variants)->toHaveCount(1)
        ->and($material->variants->first()?->code)->toBe('CPT-TARKETT-ACADEMIX-ASHEN')
        ->and($material->variants->first()?->name)->toBe('Ashen')
        ->and($material->variants->first()?->attributeValue('colourway'))->toBe('Ashen');
});

test('in-house materials use the OPAL supplier token and get a default variant', function () {
    $stone = Category::query()->where('code', 'STN')->sole();

    $material = app(CreateMaterial::class)->handle(['name' => 'Travertine', 'category_id' => $stone->getKey()]);

    expect($material->code)->toBe('STN-OPAL-TRAVERTINE')
        ->and($material->variants->first()?->code)->toBe('STN-OPAL-TRAVERTINE-DEFAULT');
});

test('colliding codes receive a numeric suffix', function () {
    $category = Category::factory()->create(['code' => 'TST']);
    $supplier = Supplier::factory()->create(['name' => 'Acme']);

    $first = Material::factory()->create(['name' => 'Oak', 'category_id' => $category, 'supplier_id' => $supplier]);
    $second = Material::factory()->create(['name' => 'Oak!', 'category_id' => $category, 'supplier_id' => $supplier]);

    expect($first->code)->toBe('TST-ACME-OAK')
        ->and($second->code)->toBe('TST-ACME-OAK_2');

    $variantA = app(AddVariant::class)->handle($first, [], 'Natural');
    $variantB = app(AddVariant::class)->handle($first, [], 'Natural!');

    expect($variantA->code)->toBe('TST-ACME-OAK-NATURAL')
        ->and($variantB->code)->toBe('TST-ACME-OAK-NATURAL_2');
});

test('renaming never changes a code, and direct code edits are refused', function () {
    $material = Material::factory()->create(['name' => 'Oak']);
    $code = $material->code;

    $material->update(['name' => 'Oak (renamed)']);

    expect($material->fresh()?->code)->toBe($code);

    $material->code = 'XXX-YYY-ZZZ';

    expect(fn () => $material->save())->toThrow(LogicException::class);
});

test('recoding keeps the old codes as aliases for the material and its variants', function () {
    $carpet = Category::query()->where('code', 'CPT')->sole();
    $vinyl = Category::query()->where('code', 'VNL')->sole();
    $material = Material::factory()->create(['name' => 'Academix', 'category_id' => $carpet]);
    $variant = app(AddVariant::class)->handle($material, ['colourway' => 'Ashen']);
    $oldMaterialCode = $material->code;
    $oldVariantCode = $variant->code;

    app(RecodeMaterial::class)->handle($material, category: $vinyl, reason: 'recategorised');

    $material->refresh();
    $variant->refresh();

    expect($material->code)->toStartWith('VNL-')
        ->and($variant->code)->toBe($material->code.'-ASHEN')
        ->and($material->aliases()->pluck('code')->all())->toBe([$oldMaterialCode])
        ->and($variant->aliases()->pluck('code')->all())->toBe([$oldVariantCode])
        ->and(Material::resolveCode($oldMaterialCode)?->is($material))->toBeTrue()
        ->and(Material::resolveCode(strtolower($material->code))?->is($material))->toBeTrue()
        ->and(Material::resolveCode('NOPE-NOPE-NOPE'))->toBeNull();

    expect(fn () => Variant::factory()->create(['material_id' => $material, 'name' => 'Ashen']))
        ->not->toThrow(Exception::class);

    expect($material->variants()->where('name', 'Ashen')->count())->toBe(2);
});
