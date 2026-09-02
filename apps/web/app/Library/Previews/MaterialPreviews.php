<?php

namespace App\Library\Previews;

use App\Enums\ReviewState;
use App\Models\File;
use App\Models\Material;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a material looks like in a list: a real image when one exists
 * (thumbnail, base colour or reference image of a usable representation),
 * otherwise a strip of its variants' dominant colours.
 */
class MaterialPreviews
{
    public const CHIPS = 8;

    private const ROLE_PRIORITY = ['thumbnail' => 0, 'base_color' => 1, 'ref_image' => 2, 'render' => 3];

    /**
     * Preview files keyed by material id, for the given materials.
     *
     * @param  EloquentCollection<int, Material>  $materials
     * @return array<int, File>
     */
    public function filesFor(EloquentCollection $materials): array
    {
        if ($materials->isEmpty()) {
            return [];
        }

        $rows = DB::table('representation_files')
            ->join('representations', 'representations.id', '=', 'representation_files.representation_id')
            ->join('variants', 'variants.id', '=', 'representations.variant_id')
            ->join('map_roles', 'map_roles.id', '=', 'representation_files.map_role_id')
            ->whereIn('variants.material_id', $materials->modelKeys())
            ->whereIn('map_roles.slug', array_keys(self::ROLE_PRIORITY))
            ->whereIn('representations.review_state', [ReviewState::Approved->value, ReviewState::Candidate->value])
            ->get(['variants.material_id', 'representation_files.file_id', 'map_roles.slug', 'representations.review_state', 'variants.position']);

        $chosen = [];

        foreach ($rows as $row) {
            $score = (self::ROLE_PRIORITY[$row->slug] ?? 9) * 10
                + ($row->review_state === ReviewState::Approved->value ? 0 : 5)
                + min((int) $row->position, 4);

            if (! isset($chosen[$row->material_id]) || $score < $chosen[$row->material_id][0]) {
                $chosen[$row->material_id] = [$score, (int) $row->file_id];
            }
        }

        $files = File::query()->whereIn('id', array_column($chosen, 1))->get()->keyBy('id');
        $result = [];

        foreach ($chosen as $materialId => [, $fileId]) {
            if (isset($files[$fileId])) {
                $result[(int) $materialId] = $files[$fileId];
            }
        }

        return $result;
    }

    /**
     * The first few variants of each material, with their colours, keyed by material id.
     *
     * @param  EloquentCollection<int, Material>  $materials
     * @return array<int, Collection<int, Variant>>
     */
    public function chipsFor(EloquentCollection $materials, int $limit = self::CHIPS): array
    {
        if ($materials->isEmpty()) {
            return [];
        }

        $ranked = Variant::query()
            ->select(['id', 'material_id', 'name', 'code', 'dominant_hex', 'position'])
            ->selectRaw('row_number() over (partition by material_id order by position, id) as rank')
            ->whereIn('material_id', $materials->modelKeys());

        $variants = Variant::query()
            ->fromSub($ranked, 'ranked')
            ->where('rank', '<=', $limit)
            ->orderBy('material_id')
            ->orderBy('rank')
            ->get();

        return $variants->groupBy('material_id')->map(fn (Collection $group): Collection => $group->values())->all();
    }

    /**
     * Best preview file per variant, keyed by variant id.
     *
     * @param  Collection<int, Variant>  $variants
     * @return array<int, File>
     */
    public function variantFilesFor(Collection $variants): array
    {
        if ($variants->isEmpty()) {
            return [];
        }

        $rows = DB::table('representation_files')
            ->join('representations', 'representations.id', '=', 'representation_files.representation_id')
            ->join('map_roles', 'map_roles.id', '=', 'representation_files.map_role_id')
            ->whereIn('representations.variant_id', $variants->pluck('id')->all())
            ->whereIn('map_roles.slug', array_keys(self::ROLE_PRIORITY))
            ->whereIn('representations.review_state', [ReviewState::Approved->value, ReviewState::Candidate->value])
            ->get(['representations.variant_id', 'representation_files.file_id', 'map_roles.slug', 'representations.review_state']);

        $chosen = [];

        foreach ($rows as $row) {
            $score = (self::ROLE_PRIORITY[$row->slug] ?? 9) * 10 + ($row->review_state === ReviewState::Approved->value ? 0 : 5);

            if (! isset($chosen[$row->variant_id]) || $score < $chosen[$row->variant_id][0]) {
                $chosen[$row->variant_id] = [$score, (int) $row->file_id];
            }
        }

        $files = File::query()->whereIn('id', array_column($chosen, 1))->get()->keyBy('id');
        $result = [];

        foreach ($chosen as $variantId => [, $fileId]) {
            if (isset($files[$fileId])) {
                $result[(int) $variantId] = $files[$fileId];
            }
        }

        return $result;
    }

    /**
     * Targets with files per material: slug => best review state, keyed by material id.
     *
     * @param  EloquentCollection<int, Material>  $materials
     * @return array<int, array<string, string>>
     */
    public function targetsFor(EloquentCollection $materials): array
    {
        if ($materials->isEmpty()) {
            return [];
        }

        $rows = DB::table('representations')
            ->join('variants', 'variants.id', '=', 'representations.variant_id')
            ->join('targets', 'targets.id', '=', 'representations.target_id')
            ->whereIn('variants.material_id', $materials->modelKeys())
            ->whereIn('representations.review_state', [ReviewState::Approved->value, ReviewState::Candidate->value])
            ->orderBy('targets.sort_order')
            ->get(['variants.material_id', 'targets.slug', 'representations.review_state']);

        $result = [];

        foreach ($rows as $row) {
            $current = $result[$row->material_id][$row->slug] ?? null;

            if ($current !== ReviewState::Approved->value) {
                $result[(int) $row->material_id][$row->slug] = $row->review_state;
            }
        }

        return $result;
    }

    /**
     * Short labels for target badges on cards.
     *
     * @return array<string, string>
     */
    public static function badgeLabels(): array
    {
        return ['pbr' => 'PBR', 'revit' => 'RVT', 'omniverse' => 'OMNI', 'preview' => 'IMG'];
    }

    /**
     * A deterministic paper-toned fallback for a variant without a measured colour.
     */
    public static function fallbackHex(string $seed): string
    {
        $hue = hexdec(substr(md5($seed), 0, 2)) / 255;
        $palette = ['#cfcbc1', '#d9d2c4', '#c5c0b6', '#b8bfb2', '#c9c6bd', '#d6cfbf', '#bdb7ad', '#cbc3b1'];

        return $palette[(int) floor($hue * (count($palette) - 1))];
    }
}
