<?php

namespace App\Actions\Representations;

use App\Actions\Provenance\RecordProvenance;
use App\Library\Conversion\ConverterRegistry;
use App\Models\File;
use App\Models\QualityTier;
use App\Models\Representation;
use App\Models\Target;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Derive a target representation from the variant's approved canonical set,
 * as a new candidate with a "converted" provenance event.
 */
class DeriveRepresentation
{
    public function __construct(
        private readonly ConverterRegistry $converters,
        private readonly CreateRepresentation $create,
        private readonly RecordProvenance $provenance,
    ) {}

    public function handle(Variant $variant, Target|string $target, QualityTier|string $quality, User|string|null $actor = null): Representation
    {
        $target = $target instanceof Target ? $target : Target::fromSlug($target);
        $quality = $quality instanceof QualityTier ? $quality : QualityTier::fromSlug($quality);
        $canonical = $this->canonicalFor($variant, $quality);
        $converter = $this->converters->for($target);

        return DB::transaction(function () use ($variant, $target, $quality, $canonical, $converter, $actor): Representation {
            $files = $converter->convert($canonical, $target, $quality);

            $derived = $this->create->handle($variant, $target, $quality, $files, metadata: [
                'derived_from_representation_id' => $canonical->getKey(),
                'derived_from_quality' => $canonical->quality->slug,
            ], createdBy: $actor instanceof User ? $actor : null);

            $this->provenance->handle(
                $derived,
                'converted',
                $actor ?? $converter::class,
                inputs: $this->withRoles($canonical->filesByRole()),
                outputs: $this->withRoles($files),
                tool: $converter->tool(),
                toolVersion: $converter->version(),
                parameters: ['target' => $target->slug, 'quality' => $quality->slug, 'canonical_representation_id' => $canonical->getKey()],
            );

            return $derived;
        });
    }

    /**
     * The approved canonical representation at the requested quality, or the
     * closest one at or above it, or the best available below it.
     */
    public function canonicalFor(Variant $variant, QualityTier $quality): Representation
    {
        $canonicalTarget = Target::canonical() ?? throw new LogicException('No canonical target is defined.');

        $approved = Representation::query()
            ->approved()
            ->where('variant_id', $variant->getKey())
            ->where('target_id', $canonicalTarget->getKey())
            ->with('quality')
            ->get()
            ->sortBy(fn (Representation $representation): int => $representation->quality->pixels ?? 0);

        if ($approved->isEmpty()) {
            throw new LogicException("Variant [{$variant->code}] has no approved canonical representation to derive from.");
        }

        $wanted = $quality->pixels ?? 0;

        return $approved->first(fn (Representation $representation): bool => ($representation->quality->pixels ?? 0) >= $wanted)
            ?? $approved->last();
    }

    /**
     * @param  array<string, File>  $files
     * @return list<array{0: File, 1: string|null}>
     */
    private function withRoles(array $files): array
    {
        $pairs = [];

        foreach ($files as $role => $file) {
            $pairs[] = [$file, $role];
        }

        return $pairs;
    }
}
