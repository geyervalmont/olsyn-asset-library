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
    public function handle(Variant $variant): BuildRequest
    {
        $representations = Representation::query()
            ->where('variant_id', $variant->getKey())
            ->whereHas('target', fn ($query) => $query->where('is_canonical', true))
            ->approved()
            ->with(['quality', 'representationFiles.file', 'representationFiles.role'])
            ->get();

        $channels = [];

        foreach ($representations as $representation) {
            $tier = $representation->quality->slug;

            foreach ($representation->representationFiles as $entry) {
                $role = $entry->role->slug;

                // A role can appear at several tiers, but only once per tier.
                // The first approved representation wins; a second is a data
                // problem rather than something to merge silently.
                if (isset($channels[$role][$tier])) {
                    continue;
                }

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
}
