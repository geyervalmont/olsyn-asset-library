<?php

namespace App\Actions\Packaging;

use App\Library\Packaging\BuildRequest;
use App\Library\Packaging\ChannelSource;
use App\Models\Representation;
use App\Models\Variant;

/**
 * Turns a variant into the manifest the toolbox builds from.
 *
 * Only the canonical target is read. Derived targets are rebuilt from the
 * package rather than packed into it — putting a Revit image set inside the
 * archive would store a derivation as though it were a source.
 */
class AssembleBuildRequest
{
    /**
     * The toolbox's LOD ladder, in pixels along the longest edge.
     *
     * OPAL's own quality tiers are not a ladder: the legacy import created one
     * per resolution it actually found, so the library holds 1140px, 2401px,
     * 2404px and 2560px alongside 2k and 4k. Those describe what a file is; a
     * rung describes what a consumer asks for. Packaging has to translate
     * between the two, because a viewer asking for "2k" wants 2048 pixels, not
     * whatever the supplier happened to export.
     *
     * @var array<string, int>
     */
    private const RUNGS = [
        'preview' => 512,
        '1k' => 1024,
        '2k' => 2048,
        '4k' => 4096,
        '8k' => 8192,
    ];

    public function handle(Variant $variant): BuildRequest
    {
        $representations = Representation::query()
            ->where('variant_id', $variant->getKey())
            ->whereHas('target', fn ($query) => $query->where('is_canonical', true))
            ->approved()
            ->with(['quality', 'representationFiles.file', 'representationFiles.role'])
            ->get();

        $channels = [];
        $pixels = [];

        foreach ($representations as $representation) {
            foreach ($representation->representationFiles as $entry) {
                $role = $entry->role->slug;
                $source = $entry->file->width_px ?? $representation->quality->pixels;
                $tier = $this->rung($source);

                // Two sources can land on the same rung — 2401px and 2560px
                // are both 2k. Keep the larger, because downscaling to the rung
                // is lossless-ish and upscaling to it is not.
                if (isset($channels[$role][$tier]) && ($pixels[$role][$tier] ?? 0) >= (int) $source) {
                    continue;
                }

                $pixels[$role][$tier] = (int) $source;

                $channels[$role][$tier] = new ChannelSource(
                    sha256: $entry->file->sha256,
                    objectKey: $entry->file->object_key,
                    bytes: $entry->file->bytes,
                    // Stated per file. The representation records what was
                    // actually authored; the file's own value is the fallback.
                    colourSpace: $entry->colour_space ?? $entry->file->colour_space,
                    widthPx: $entry->file->width_px,
                    heightPx: $entry->file->height_px,
                    normalConvention: $role === 'normal'
                        ? ($representation->metadata['normal_convention'] ?? null)
                        : null,
                );
            }
        }

        return new BuildRequest(
            // When the material's inputs last changed, not when the packager
            // happened to run. Using the wall clock would put a fresh timestamp
            // inside every package, so two builds of identical inputs would
            // differ — and a package could never be verified by rebuilding it
            // and comparing hashes. That check is worth more than recording a
            // build time the database already holds in packages.built_at.
            ingestedAt: $representations->max('updated_at')?->toRfc3339String()
                ?? $variant->updated_at?->toRfc3339String(),
            // Never ask for a rung nothing can supply: the toolbox downscales
            // what is missing below the largest source and fails rather than
            // inventing detail above it, which is the behaviour we want.
            requiredTiers: $this->rungsUpTo($channels),
            variantCode: $variant->code,
            name: $variant->name,
            channels: $channels,
            tiling: array_filter([
                'width_mm' => $variant->effectiveTileWidthMm(),
                'height_mm' => $variant->effectiveTileHeightMm(),
                'repeat' => $variant->repeat_type,
                'install_pattern' => $variant->install_pattern,
            ], fn ($value): bool => $value !== null),
            provenance: [
                'material' => $variant->material->code,
                'variant' => $variant->code,
            ],
        );
    }

    /**
     * The largest rung a source of this size can fill without being upscaled.
     */
    private function rung(?int $pixels): string
    {
        $chosen = array_key_first(self::RUNGS);

        foreach (self::RUNGS as $slug => $edge) {
            if (($pixels ?? 0) >= $edge) {
                $chosen = $slug;
            }
        }

        return $chosen;
    }

    /**
     * Every rung from preview up to the largest one *every* channel can fill.
     *
     * required_tiers is a floor the toolbox applies to every map, so it has to
     * be satisfiable by the smallest one. Taking the largest instead asks a
     * 489px normal for a 1k tier beside a 4K base colour, and the toolbox
     * refuses rather than upscaling — which is the behaviour we want, so the
     * request is what has to change.
     *
     * Nothing is lost by asking for less: higher tiers that do exist are still
     * packaged. The floor only says what must be present everywhere.
     *
     * @param  array<string, array<string, ChannelSource>>  $channels
     * @return list<string>
     */
    private function rungsUpTo(array $channels): array
    {
        $ceiling = null;

        foreach ($channels as $sources) {
            $best = 0;

            foreach (array_keys($sources) as $tier) {
                $best = max($best, self::RUNGS[$tier] ?? 0);
            }

            $ceiling = $ceiling === null ? $best : min($ceiling, $best);
        }

        $ceiling ??= 0;

        $wanted = [];

        foreach (self::RUNGS as $slug => $edge) {
            if ($edge <= $ceiling) {
                $wanted[] = $slug;
            }
        }

        return $wanted === [] ? ['preview'] : $wanted;
    }
}
