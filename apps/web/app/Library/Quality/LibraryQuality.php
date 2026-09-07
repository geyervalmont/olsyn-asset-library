<?php

namespace App\Library\Quality;

use App\Enums\ReviewState;
use App\Models\Material;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * What the library is missing.
 *
 * Every material is measured against what a usable material needs: canonical
 * maps at a workable size, the maps that carry surface detail, a set Revit can
 * read, and a rendered preview. The gaps are the queue of work, whether that
 * work is a person uploading a file or, later, a worker generating one.
 */
class LibraryQuality
{
    /** Below this, a canonical set is too small to apply to real geometry. */
    public const LOW_PIXELS = 1024;

    /** The maps a canonical set should carry beyond its base colour. */
    public const DETAIL_ROLES = ['normal', 'roughness', 'ao'];

    /** The maps a full PBR set carries, in the order they matter. */
    public const PBR_ROLES = ['base_color', 'normal', 'roughness', 'ao', 'metallic'];

    /** Canonical resolution bands, coarsest signal of how usable a set is. */
    public const BANDS = ['none', 'under-1k', '1k', '2k', '4k'];

    /** Gaps, in the order they are worth fixing. */
    public const GAPS = ['no-canonical', 'low-resolution', 'no-normal', 'no-roughness', 'no-ao', 'no-revit', 'no-preview', 'unpublished'];

    /**
     * Materials with their quality signals, worst first when asked.
     *
     * @param  list<string>  $gaps  Only materials with all of these gaps.
     * @return LengthAwarePaginator<int, Material>
     */
    public function materials(?User $viewer, array $gaps = [], string $search = '', string $sort = 'worst', int $perPage = 25, string $band = ''): LengthAwarePaginator
    {
        $query = $this->query($viewer, $gaps, $search);

        if (in_array($band, self::BANDS, true)) {
            $query->whereRaw($this->bandCondition($band));
        }

        $query = $sort === 'name'
            ? $query->orderBy('materials.name')
            // Nothing at all first, then the smallest maps, then by name.
            : $query->orderByRaw('(signals.canonical_pixels is null) desc')
                ->orderByRaw('signals.canonical_pixels asc nulls first')
                ->orderBy('materials.name');

        return $query->paginate($perPage)->through(function (Material $material): Material {
            $material->setAttribute('gaps', $this->gapsFor($material));
            $material->setAttribute('reason', $this->reasonFor($material));

            return $material;
        });
    }

    /**
     * How many materials sit in each gap, for the whole visible library.
     *
     * @return array{total: int, with_files: int, gaps: array<string, int>}
     */
    public function summary(?User $viewer): array
    {
        $row = Material::query()
            ->visibleTo($viewer)
            ->leftJoinSub($this->signals(), 'signals', 'signals.material_id', '=', 'materials.id')
            ->selectRaw("
                count(*) as total,
                count(*) filter (where signals.material_id is not null) as with_files,
                count(*) filter (where not (','||coalesce(signals.targets, '')||',' like '%,pbr,%')) as no_canonical,
                count(*) filter (where ','||coalesce(signals.targets, '')||',' like '%,pbr,%' and signals.canonical_pixels < 1024) as low_resolution,
                count(*) filter (where ','||coalesce(signals.targets, '')||',' like '%,pbr,%' and not (','||coalesce(signals.canonical_roles, '')||',' like '%,normal,%')) as no_normal,
                count(*) filter (where ','||coalesce(signals.targets, '')||',' like '%,pbr,%' and not (','||coalesce(signals.canonical_roles, '')||',' like '%,roughness,%')) as no_roughness,
                count(*) filter (where ','||coalesce(signals.targets, '')||',' like '%,pbr,%' and not (','||coalesce(signals.canonical_roles, '')||',' like '%,ao,%')) as no_ao,
                count(*) filter (where not (','||coalesce(signals.targets, '')||',' like '%,revit,%')) as no_revit,
                count(*) filter (where not (','||coalesce(signals.targets, '')||',' like '%,preview,%')) as no_preview,
                count(*) filter (where materials.current_version_id is null) as unpublished
            ")
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'with_files' => (int) ($row->with_files ?? 0),
            'gaps' => [
                'no-canonical' => (int) ($row->no_canonical ?? 0),
                'low-resolution' => (int) ($row->low_resolution ?? 0),
                'no-normal' => (int) ($row->no_normal ?? 0),
                'no-roughness' => (int) ($row->no_roughness ?? 0),
                'no-ao' => (int) ($row->no_ao ?? 0),
                'no-revit' => (int) ($row->no_revit ?? 0),
                'no-preview' => (int) ($row->no_preview ?? 0),
                'unpublished' => (int) ($row->unpublished ?? 0),
            ],
        ];
    }

    /**
     * PBR coverage: of the materials that have a canonical set, how many carry
     * each map. This is the shape of the work an upscaler or map generator
     * would pick up.
     *
     * @return array{with_canonical: int, roles: array<string, int>}
     */
    public function coverage(?User $viewer): array
    {
        $row = Material::query()
            ->visibleTo($viewer)
            ->leftJoinSub($this->signals(), 'signals', 'signals.material_id', '=', 'materials.id')
            ->selectRaw("
                count(*) filter (where ','||coalesce(signals.targets, '')||',' like '%,pbr,%') as with_canonical,
                count(*) filter (where ','||coalesce(signals.canonical_roles, '')||',' like '%,base_color,%') as base_color,
                count(*) filter (where ','||coalesce(signals.canonical_roles, '')||',' like '%,normal,%') as normal,
                count(*) filter (where ','||coalesce(signals.canonical_roles, '')||',' like '%,roughness,%') as roughness,
                count(*) filter (where ','||coalesce(signals.canonical_roles, '')||',' like '%,ao,%') as ao,
                count(*) filter (where ','||coalesce(signals.canonical_roles, '')||',' like '%,metallic,%') as metallic
            ")
            ->first();

        return [
            'with_canonical' => (int) ($row->with_canonical ?? 0),
            'roles' => [
                'base_color' => (int) ($row->base_color ?? 0),
                'normal' => (int) ($row->normal ?? 0),
                'roughness' => (int) ($row->roughness ?? 0),
                'ao' => (int) ($row->ao ?? 0),
                'metallic' => (int) ($row->metallic ?? 0),
            ],
        ];
    }

    /**
     * How many materials sit in each canonical resolution band.
     *
     * @return array<string, int>
     */
    public function distribution(?User $viewer): array
    {
        $row = Material::query()
            ->visibleTo($viewer)
            ->leftJoinSub($this->signals(), 'signals', 'signals.material_id', '=', 'materials.id')
            ->selectRaw("
                count(*) filter (where not (','||coalesce(signals.targets, '')||',' like '%,pbr,%')) as none,
                count(*) filter (where ','||coalesce(signals.targets, '')||',' like '%,pbr,%' and signals.canonical_pixels < 1024) as under_1k,
                count(*) filter (where signals.canonical_pixels >= 1024 and signals.canonical_pixels < 2048) as k1,
                count(*) filter (where signals.canonical_pixels >= 2048 and signals.canonical_pixels < 4096) as k2,
                count(*) filter (where signals.canonical_pixels >= 4096) as k4
            ")
            ->first();

        return [
            'none' => (int) ($row->none ?? 0),
            'under-1k' => (int) ($row->under_1k ?? 0),
            '1k' => (int) ($row->k1 ?? 0),
            '2k' => (int) ($row->k2 ?? 0),
            '4k' => (int) ($row->k4 ?? 0),
        ];
    }

    /**
     * Why a material has nothing to show, when that is the case.
     *
     * A material with no files at all is waiting on its files; one whose only
     * files sit on other targets has nothing to derive a preview or a Revit
     * set from, because both come from the canonical set.
     */
    public function reasonFor(Material $material): ?string
    {
        $targets = $this->list($material->getAttribute('targets'));

        if ($targets === []) {
            return 'no-files';
        }

        return in_array('pbr', $targets, true) ? null : 'non-canonical';
    }

    /**
     * The gaps of one measured material.
     *
     * @return list<string>
     */
    public function gapsFor(Material $material): array
    {
        $roles = $this->list($material->getAttribute('canonical_roles'));
        $targets = $this->list($material->getAttribute('targets'));
        $pixels = $material->getAttribute('canonical_pixels');
        $gaps = [];

        $canonical = in_array('pbr', $targets, true);

        if (! $canonical) {
            $gaps[] = 'no-canonical';
        }

        if ($canonical && $pixels !== null && (int) $pixels < self::LOW_PIXELS) {
            $gaps[] = 'low-resolution';
        }

        if ($canonical) {
            foreach (self::DETAIL_ROLES as $role) {
                if (! in_array($role, $roles, true)) {
                    $gaps[] = 'no-'.$role;
                }
            }
        }

        if (! in_array('revit', $targets, true)) {
            $gaps[] = 'no-revit';
        }

        if (! in_array('preview', $targets, true)) {
            $gaps[] = 'no-preview';
        }

        if ($material->current_version_id === null) {
            $gaps[] = 'unpublished';
        }

        return $gaps;
    }

    /**
     * @param  list<string>  $gaps
     * @return Builder<Material>
     */
    private function query(?User $viewer, array $gaps, string $search): Builder
    {
        $query = Material::query()
            ->visibleTo($viewer)
            ->search($search)
            ->with(['category', 'supplier'])
            ->withCount('variants')
            ->leftJoinSub($this->signals(), 'signals', 'signals.material_id', '=', 'materials.id')
            ->select('materials.*')
            ->addSelect(['signals.canonical_pixels', 'signals.canonical_roles', 'signals.targets']);

        foreach (array_intersect($gaps, self::GAPS) as $gap) {
            $query->whereRaw($this->condition($gap));
        }

        return $query;
    }

    /**
     * Per material: the best canonical size, its canonical map roles, and
     * every target that has files worth counting.
     */
    private function signals(): QueryBuilder
    {
        $states = [ReviewState::Approved->value, ReviewState::Candidate->value];

        return DB::table('representations')
            ->join('variants', 'variants.id', '=', 'representations.variant_id')
            ->join('targets', 'targets.id', '=', 'representations.target_id')
            ->leftJoin('quality_tiers', 'quality_tiers.id', '=', 'representations.quality_tier_id')
            ->leftJoin('representation_files', 'representation_files.representation_id', '=', 'representations.id')
            ->leftJoin('map_roles', 'map_roles.id', '=', 'representation_files.map_role_id')
            ->whereIn('representations.review_state', $states)
            ->groupBy('variants.material_id')
            ->select('variants.material_id')
            ->selectRaw('max(case when targets.is_canonical then quality_tiers.pixels end) as canonical_pixels')
            ->selectRaw("string_agg(distinct case when targets.is_canonical then map_roles.slug end, ',') as canonical_roles")
            ->selectRaw("string_agg(distinct targets.slug, ',') as targets");
    }

    /**
     * SQL for one gap, over the joined signals.
     *
     * @return literal-string
     */
    private function condition(string $gap): string
    {
        return match ($gap) {
            'no-canonical' => "not (','||coalesce(signals.targets, '')||',' like '%,pbr,%')",
            'low-resolution' => "','||coalesce(signals.targets, '')||',' like '%,pbr,%' and signals.canonical_pixels < 1024",
            'no-normal' => "','||coalesce(signals.targets, '')||',' like '%,pbr,%' and not (','||coalesce(signals.canonical_roles, '')||',' like '%,normal,%')",
            'no-roughness' => "','||coalesce(signals.targets, '')||',' like '%,pbr,%' and not (','||coalesce(signals.canonical_roles, '')||',' like '%,roughness,%')",
            'no-ao' => "','||coalesce(signals.targets, '')||',' like '%,pbr,%' and not (','||coalesce(signals.canonical_roles, '')||',' like '%,ao,%')",
            'no-revit' => "not (','||coalesce(signals.targets, '')||',' like '%,revit,%')",
            'no-preview' => "not (','||coalesce(signals.targets, '')||',' like '%,preview,%')",
            'unpublished' => 'materials.current_version_id is null',
            default => 'true',
        };
    }

    /**
     * SQL for one resolution band.
     *
     * @return literal-string
     */
    private function bandCondition(string $band): string
    {
        return match ($band) {
            'none' => "not (','||coalesce(signals.targets, '')||',' like '%,pbr,%')",
            'under-1k' => "','||coalesce(signals.targets, '')||',' like '%,pbr,%' and signals.canonical_pixels < 1024",
            '1k' => 'signals.canonical_pixels >= 1024 and signals.canonical_pixels < 2048',
            '2k' => 'signals.canonical_pixels >= 2048 and signals.canonical_pixels < 4096',
            '4k' => 'signals.canonical_pixels >= 4096',
            default => 'true',
        };
    }

    /**
     * @return list<string>
     */
    private function list(mixed $value): array
    {
        return $value === null || $value === '' ? [] : array_values(array_filter(explode(',', (string) $value)));
    }
}
