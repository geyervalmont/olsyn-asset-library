<?php

use App\Actions\Materials\CreateMaterial;
use App\Models\Category;
use App\Models\Material;
use App\Models\Supplier;
use App\Models\Variant;
use Database\Seeders\LibrarySeeder;

beforeEach(function () {
    $this->seed(LibrarySeeder::class);

    $carpet = Category::query()->where('code', 'CPT')->sole();
    $timber = Category::query()->where('code', 'TMB')->sole();
    $tarkett = Supplier::factory()->create(['name' => 'Tarkett']);
    $havwoods = Supplier::factory()->create(['name' => 'Havwoods']);

    $this->academix = app(CreateMaterial::class)->handle(
        ['name' => 'Academix', 'category_id' => $carpet->getKey(), 'supplier_id' => $tarkett->getKey(), 'supplier_product_code' => '634014'],
        [
            ['attributes' => ['colourway' => ['value' => 'Ashen', 'supplier_code' => '634014001']]],
            ['attributes' => ['colourway' => ['value' => 'Slate', 'supplier_code' => '634014002']]],
        ],
        ['commercial'],
    );

    $this->oak = app(CreateMaterial::class)->handle(
        ['name' => 'H Collection', 'category_id' => $timber->getKey(), 'supplier_id' => $havwoods->getKey()],
        [['attributes' => ['colourway' => 'Oak Natural', 'finish' => 'Brushed']]],
    );
});

test('materials are found by name, supplier, product code, colourway, tag and category', function () {
    $find = fn (string $term): array => Material::query()->search($term)->pluck('code')->all();

    expect($find('academix'))->toBe([$this->academix->code])
        ->and($find('tarkett'))->toBe([$this->academix->code])
        ->and($find('634014002'))->toBe([$this->academix->code])
        ->and($find('slate'))->toBe([$this->academix->code])
        ->and($find('commercial'))->toBe([$this->academix->code])
        ->and($find('timber'))->toBe([$this->oak->code])
        ->and($find('brushed oak'))->toBe([$this->oak->code])
        ->and($find('granite'))->toBe([]);
});

test('search tolerates misspellings through trigram similarity', function () {
    expect(Material::query()->search('acadmix')->pluck('code')->all())->toBe([$this->academix->code])
        ->and(Material::query()->search('havwods')->pluck('code')->all())->toBe([$this->oak->code]);
});

test('variants are searchable on their own and stay in sync with attribute changes', function () {
    expect(Variant::query()->search('ashen')->pluck('code')->all())->toBe([$this->academix->code.'-ASHEN']);

    $slate = Variant::query()->where('code', $this->academix->code.'-SLATE')->sole();
    $slate->attributes()->first()?->update(['value' => 'Graphite']);
    $slate->update(['name' => 'Graphite']);

    expect(Variant::query()->search('graphite')->pluck('code')->all())->toBe([$slate->code])
        ->and($slate->fresh()?->code)->toBe($this->academix->code.'-SLATE')
        ->and(Material::query()->search('graphite')->pluck('code')->all())->toBe([$this->academix->code])
        ->and(Material::query()->search('slate')->pluck('code')->all())->toBe([]);
});

test('an empty term returns everything and filters compose with search', function () {
    expect(Material::query()->search('   ')->count())->toBe(2)
        ->and(Material::query()->search('tarkett')->whereRelation('category', 'code', 'TMB')->count())->toBe(0)
        ->and(Material::query()->search('tarkett')->whereRelation('category', 'code', 'CPT')->count())->toBe(1);
});
