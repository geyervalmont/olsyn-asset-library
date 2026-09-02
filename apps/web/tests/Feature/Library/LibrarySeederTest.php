<?php

use App\Models\Category;
use App\Models\Material;
use App\Models\Supplier;
use App\Models\VariantType;
use Database\Seeders\DemoMaterialsSeeder;
use Database\Seeders\LibrarySeeder;

test('the library seed is idempotent and admin edits survive it', function () {
    $this->seed(LibrarySeeder::class);
    $this->seed(LibrarySeeder::class);

    expect(Category::query()->where('kind', 'material')->count())->toBe(count(LibrarySeeder::MATERIAL_CATEGORIES))
        ->and(VariantType::query()->count())->toBe(count(LibrarySeeder::VARIANT_TYPES))
        ->and(Supplier::query()->where('code', 'OPAL')->exists())->toBeTrue();

    Category::query()->create(['code' => 'GLS', 'name' => 'Glass']);
    $this->seed(LibrarySeeder::class);

    expect(Category::query()->where('code', 'GLS')->exists())->toBeTrue();
});

test('demo materials seed only once and look like real records', function () {
    $this->seed(LibrarySeeder::class);
    $this->seed(DemoMaterialsSeeder::class);
    $this->seed(DemoMaterialsSeeder::class);

    expect(Material::query()->count())->toBe(2)
        ->and(Material::resolveCode('CPT-TARKETT-ACADEMIX')?->variants()->count())->toBe(2)
        ->and(Material::resolveCode('STN-OPAL-TRAVERTINE')?->variants()->pluck('code')->all())
        ->toBe(['STN-OPAL-TRAVERTINE-HONED', 'STN-OPAL-TRAVERTINE-POLISHED']);
});
