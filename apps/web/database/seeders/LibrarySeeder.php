<?php

namespace Database\Seeders;

use App\Library\MaterialCodes;
use App\Models\Category;
use App\Models\MapRole;
use App\Models\Platform;
use App\Models\ProvenanceAction;
use App\Models\QualityTier;
use App\Models\Source;
use App\Models\Supplier;
use App\Models\Target;
use App\Models\VariantType;
use Illuminate\Database\Seeder;

/**
 * Library vocabularies. Idempotent: rows are matched by code or slug and
 * updated in place, so admins can add more without fighting the seed.
 */
class LibrarySeeder extends Seeder
{
    /** @var list<array{code: string, name: string}> */
    public const MATERIAL_CATEGORIES = [
        ['code' => 'ACP', 'name' => 'Acoustic panelling'],
        ['code' => 'ANO', 'name' => 'Anodised and electrospray'],
        ['code' => 'CPT', 'name' => 'Carpet'],
        ['code' => 'CER', 'name' => 'Ceramic'],
        ['code' => 'CUR', 'name' => 'Curtain'],
        ['code' => 'FAB', 'name' => 'Fabric'],
        ['code' => 'PLS', 'name' => 'Feature paint and plaster'],
        ['code' => 'LAM', 'name' => 'Laminate'],
        ['code' => 'LTH', 'name' => 'Leather'],
        ['code' => 'PNT', 'name' => 'Paint'],
        ['code' => 'SOL', 'name' => 'Solid surface'],
        ['code' => 'STN', 'name' => 'Stone'],
        ['code' => 'TMB', 'name' => 'Timber'],
        ['code' => 'VNR', 'name' => 'Veneer'],
        ['code' => 'VNL', 'name' => 'Vinyl flooring'],
    ];

    /** @var list<array{slug: string, name: string, description: string}> */
    public const VARIANT_TYPES = [
        ['slug' => 'colourway', 'name' => 'Colourway', 'description' => 'A colour option of the same product.'],
        ['slug' => 'saturation', 'name' => 'Saturation', 'description' => 'A tinted or desaturated take on a colourway.'],
        ['slug' => 'finish', 'name' => 'Finish', 'description' => 'Surface finish: matte, satin, gloss, honed, polished.'],
        ['slug' => 'pattern', 'name' => 'Pattern', 'description' => 'Emboss, etch, weave or lay pattern.'],
        ['slug' => 'format', 'name' => 'Format', 'description' => 'Module size or plank width.'],
    ];

    /** @var list<array{slug: string, name: string, description: string}> */
    public const PROVENANCE_ACTIONS = [
        ['slug' => 'uploaded', 'name' => 'Uploaded', 'description' => 'A person added the file by hand.'],
        ['slug' => 'downloaded', 'name' => 'Downloaded', 'description' => 'Fetched from a supplier or platform page.'],
        ['slug' => 'scraped', 'name' => 'Scraped', 'description' => 'Collected automatically from a website.'],
        ['slug' => 'imported', 'name' => 'Imported', 'description' => 'Brought in from another system or the legacy library.'],
        ['slug' => 'generated', 'name' => 'Generated', 'description' => 'Produced from a prompt or procedure.'],
        ['slug' => 'upscaled', 'name' => 'Upscaled', 'description' => 'Resolution increased.'],
        ['slug' => 'tiled', 'name' => 'Made seamless', 'description' => 'Edges reworked to tile.'],
        ['slug' => 'edited', 'name' => 'Edited', 'description' => 'Adjusted by a person in an image tool.'],
        ['slug' => 'converted', 'name' => 'Converted', 'description' => 'Translated to another format or target.'],
        ['slug' => 'rendered', 'name' => 'Rendered', 'description' => 'A preview or render produced from the material.'],
        ['slug' => 'approved', 'name' => 'Approved', 'description' => 'A reviewer accepted the result.'],
        ['slug' => 'rejected', 'name' => 'Rejected', 'description' => 'A reviewer declined the result.'],
    ];

    /** @var list<array{slug: string, name: string, is_canonical?: bool, description: string}> */
    public const TARGETS = [
        ['slug' => 'pbr', 'name' => 'PBR (canonical)', 'is_canonical' => true, 'description' => 'The master texture set every other target is derived from.'],
        ['slug' => 'revit', 'name' => 'Revit', 'description' => 'Image set and mapping for Revit; Enscape reads the Revit material.'],
        ['slug' => 'omniverse', 'name' => 'Omniverse', 'description' => 'MDL and USD for Omniverse.'],
        ['slug' => 'preview', 'name' => 'Preview', 'description' => 'Thumbnails and renders for the library UI.'],
    ];

    /** @var list<array{slug: string, name: string, pixels: int|null}> */
    public const QUALITY_TIERS = [
        ['slug' => 'preview', 'name' => 'Preview', 'pixels' => 512],
        ['slug' => '1k', 'name' => '1K', 'pixels' => 1024],
        ['slug' => '2k', 'name' => '2K', 'pixels' => 2048],
        ['slug' => '4k', 'name' => '4K', 'pixels' => 4096],
        ['slug' => '8k', 'name' => '8K', 'pixels' => 8192],
    ];

    /** @var list<array{slug: string, name: string, colour_space: string|null}> */
    public const MAP_ROLES = [
        ['slug' => 'base_color', 'name' => 'Base colour', 'colour_space' => 'srgb'],
        ['slug' => 'normal', 'name' => 'Normal (OpenGL)', 'colour_space' => 'linear'],
        ['slug' => 'roughness', 'name' => 'Roughness', 'colour_space' => 'linear'],
        ['slug' => 'glossiness', 'name' => 'Glossiness', 'colour_space' => 'linear'],
        ['slug' => 'metallic', 'name' => 'Metallic', 'colour_space' => 'linear'],
        ['slug' => 'height', 'name' => 'Height', 'colour_space' => 'linear'],
        ['slug' => 'bump', 'name' => 'Bump', 'colour_space' => 'linear'],
        ['slug' => 'ao', 'name' => 'Ambient occlusion', 'colour_space' => 'linear'],
        ['slug' => 'opacity', 'name' => 'Opacity', 'colour_space' => 'linear'],
        ['slug' => 'specular', 'name' => 'Specular', 'colour_space' => 'linear'],
        ['slug' => 'transmission', 'name' => 'Transmission', 'colour_space' => 'linear'],
        ['slug' => 'emissive', 'name' => 'Emissive', 'colour_space' => 'srgb'],
        ['slug' => 'ref_image', 'name' => 'Reference image', 'colour_space' => 'srgb'],
        ['slug' => 'render', 'name' => 'Render', 'colour_space' => 'srgb'],
        ['slug' => 'thumbnail', 'name' => 'Thumbnail', 'colour_space' => 'srgb'],
        ['slug' => 'mdl', 'name' => 'MDL module', 'colour_space' => null],
        ['slug' => 'usd', 'name' => 'USD', 'colour_space' => null],
        ['slug' => 'rvt', 'name' => 'Revit material library', 'colour_space' => null],
        ['slug' => 'package', 'name' => 'Package', 'colour_space' => null],
    ];

    /** @var list<array{slug: string, name: string, target: string, description: string}> */
    public const PLATFORMS = [
        ['slug' => 'revit', 'name' => 'Revit', 'target' => 'revit', 'description' => 'Autodesk Revit appearance assets.'],
        ['slug' => 'enscape', 'name' => 'Enscape', 'target' => 'revit', 'description' => 'Reads the Revit material; no identity of its own.'],
        ['slug' => 'omniverse', 'name' => 'Omniverse', 'target' => 'omniverse', 'description' => 'NVIDIA Omniverse via MDL and USD.'],
    ];

    public function run(): void
    {
        foreach (self::TARGETS as $position => $target) {
            Target::query()->updateOrCreate(
                ['slug' => $target['slug']],
                ['name' => $target['name'], 'description' => $target['description'], 'is_canonical' => $target['is_canonical'] ?? false, 'sort_order' => $position],
            );
        }

        foreach (self::QUALITY_TIERS as $position => $tier) {
            QualityTier::query()->updateOrCreate(
                ['slug' => $tier['slug']],
                ['name' => $tier['name'], 'pixels' => $tier['pixels'], 'sort_order' => $position],
            );
        }

        foreach (self::MAP_ROLES as $position => $role) {
            MapRole::query()->updateOrCreate(
                ['slug' => $role['slug']],
                ['name' => $role['name'], 'colour_space' => $role['colour_space'], 'sort_order' => $position],
            );
        }

        foreach (self::PROVENANCE_ACTIONS as $position => $action) {
            ProvenanceAction::query()->updateOrCreate(
                ['slug' => $action['slug']],
                ['name' => $action['name'], 'description' => $action['description'], 'sort_order' => $position],
            );
        }

        foreach (self::MATERIAL_CATEGORIES as $position => $category) {
            Category::query()->updateOrCreate(
                ['kind' => Category::KIND_MATERIAL, 'code' => $category['code']],
                ['name' => $category['name'], 'sort_order' => $position],
            );
        }

        foreach (self::VARIANT_TYPES as $position => $type) {
            VariantType::query()->updateOrCreate(
                ['slug' => $type['slug']],
                ['name' => $type['name'], 'description' => $type['description'], 'sort_order' => $position],
            );
        }

        foreach (self::PLATFORMS as $position => $platform) {
            Platform::query()->updateOrCreate(
                ['slug' => $platform['slug']],
                ['name' => $platform['name'], 'target_id' => Target::fromSlug($platform['target'])->getKey(), 'description' => $platform['description'], 'sort_order' => $position],
            );
        }

        $inHouse = Supplier::query()->updateOrCreate(
            ['code' => MaterialCodes::IN_HOUSE],
            ['name' => 'OPAL (in-house)', 'slug' => 'opal'],
        );

        Source::query()->updateOrCreate(
            ['slug' => 'opal-in-house'],
            ['name' => 'OPAL in-house production', 'kind' => 'in_house', 'supplier_id' => $inHouse->getKey()],
        );
    }
}
