<?php

use App\Actions\Materials\AddVariant;
use App\Models\Material;
use App\Models\VariantType;
use Database\Seeders\LibrarySeeder;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->seed(LibrarySeeder::class);
});

test('a variant combines typed attributes and names itself from them', function () {
    $material = Material::factory()->create();

    $variant = app(AddVariant::class)->handle($material, [
        'colourway' => ['value' => 'Ashen', 'supplier_code' => '634014001', 'supplier_name' => 'ASHEN'],
        'saturation' => 'muted',
    ]);

    expect($variant->name)->toBe('Ashen, muted')
        ->and($variant->token)->toBe('ASHEN_MUTED')
        ->and($variant->attributes)->toHaveCount(2)
        ->and($variant->attributeValue('colourway'))->toBe('Ashen')
        ->and($variant->attributeValue('saturation'))->toBe('muted')
        ->and($variant->attributeValue('finish'))->toBeNull()
        ->and($variant->attributes->firstWhere('supplier_code', '634014001')?->type->slug)->toBe('colourway');
});

test('a variant holds at most one value per type and only known types', function () {
    $material = Material::factory()->create();
    $variant = app(AddVariant::class)->handle($material, ['colourway' => 'Ashen']);
    $colourway = VariantType::findBySlug('colourway');

    expect(fn () => app(AddVariant::class)->handle($material, ['flavour' => 'vanilla']))
        ->toThrow(InvalidArgumentException::class);

    // Last, because a constraint violation aborts the surrounding Postgres transaction.
    expect(fn () => $variant->attributes()->create(['variant_type_id' => $colourway?->getKey(), 'value' => 'Slate']))
        ->toThrow(QueryException::class);
});

test('variants inherit physical dimensions from the material unless they override them', function () {
    $material = Material::factory()->create(['tile_width_mm' => 500, 'tile_height_mm' => 500]);
    $standard = app(AddVariant::class)->handle($material, ['format' => '500 x 500']);
    $plank = app(AddVariant::class)->handle($material, ['format' => 'Plank'], overrides: ['tile_width_mm' => 250, 'tile_height_mm' => 1000]);

    expect((float) $standard->effectiveTileWidthMm())->toBe(500.0)
        ->and((float) $plank->effectiveTileWidthMm())->toBe(250.0)
        ->and((float) $plank->effectiveTileHeightMm())->toBe(1000.0)
        ->and($plank->position)->toBeGreaterThan($standard->position);
});

test('variant types are data and can be added without code changes', function () {
    VariantType::query()->create(['slug' => 'weave', 'name' => 'Weave']);
    $material = Material::factory()->create();

    $variant = app(AddVariant::class)->handle($material, ['weave' => 'Twill']);

    expect($variant->attributeValue('weave'))->toBe('Twill')
        ->and($variant->code)->toEndWith('-TWILL');
});
