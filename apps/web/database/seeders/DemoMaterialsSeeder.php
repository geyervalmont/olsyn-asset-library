<?php

namespace Database\Seeders;

use App\Actions\Materials\CreateMaterial;
use App\Enums\MaterialStatus;
use App\Models\Category;
use App\Models\Drive;
use App\Models\Material;
use App\Models\Source;
use App\Models\Supplier;
use Illuminate\Database\Seeder;

/**
 * A couple of representative records for local development, shaped after
 * real entries in the legacy library.
 */
class DemoMaterialsSeeder extends Seeder
{
    public function run(CreateMaterial $createMaterial): void
    {
        Drive::query()->firstOrCreate(['slug' => 'studio-share'], ['name' => 'Studio share', 'root_path' => '/materials']);

        if (Material::query()->exists()) {
            return;
        }

        $tarkett = Supplier::query()->firstOrCreate(['code' => 'TARKETT'], ['name' => 'Tarkett', 'website' => 'https://www.tarkett.com']);
        $tarkettSource = Source::query()->firstOrCreate(
            ['slug' => 'tarkett-website'],
            ['name' => 'Tarkett website', 'kind' => 'supplier', 'supplier_id' => $tarkett->getKey(), 'url' => 'https://www.tarkett.com'],
        );

        $createMaterial->handle(
            [
                'name' => 'Academix',
                'category_id' => Category::query()->where('code', 'CPT')->sole()->getKey(),
                'supplier_id' => $tarkett->getKey(),
                'source_id' => $tarkettSource->getKey(),
                'collection' => 'Academix',
                'supplier_product_code' => '634014',
                'material_type' => 'carpet tile',
                'form' => 'tile',
                'tile_width_mm' => 500,
                'tile_height_mm' => 500,
                'repeat_type' => 'tile',
                'status' => MaterialStatus::Active,
            ],
            [
                ['attributes' => ['colourway' => ['value' => 'Ashen', 'supplier_code' => '634014001']]],
                ['attributes' => ['colourway' => ['value' => 'Slate', 'supplier_code' => '634014002']]],
            ],
            ['commercial', 'loop pile'],
        );

        $createMaterial->handle(
            [
                'name' => 'Travertine',
                'category_id' => Category::query()->where('code', 'STN')->sole()->getKey(),
                'source_id' => Source::query()->where('slug', 'opal-in-house')->sole()->getKey(),
                'material_type' => 'natural stone',
                'form' => 'slab',
                'status' => MaterialStatus::Active,
            ],
            [
                ['attributes' => ['finish' => 'Honed']],
                ['attributes' => ['finish' => 'Polished']],
            ],
            ['stone', 'natural'],
        );
    }
}
