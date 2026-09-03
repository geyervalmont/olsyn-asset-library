<?php

namespace App\Jobs;

use App\Actions\Provenance\RecordProvenance;
use App\Actions\Representations\CreateRepresentation;
use App\Enums\ReviewState;
use App\Library\FileStore;
use App\Library\Workers\ImageResizer;
use App\Models\File;
use App\Models\QualityTier;
use App\Models\Representation;
use App\Models\User;
use App\Models\WorkerRun;

/**
 * Produce lower quality tiers of a representation's image maps. The result
 * is deterministic, so a tier derived from an approved set is approved too.
 */
class DownscaleRepresentation extends TrackedJob
{
    public static function type(): string
    {
        return 'downscale_representation';
    }

    /**
     * @param  list<string>  $tiers
     */
    public static function forRepresentation(Representation $representation, array $tiers, ?User $actor = null): WorkerRun
    {
        return static::launch(['representation_id' => $representation->getKey(), 'tiers' => $tiers], $representation, $actor);
    }

    protected function execute(WorkerRun $run): ?array
    {
        $source = Representation::query()->with(['variant', 'target', 'quality', 'representationFiles.file', 'representationFiles.role'])->findOrFail((int) ($run->payload['representation_id'] ?? 0));
        $sourcePixels = $source->quality->pixels ?? $this->largestEdge($source);
        $resizer = app(ImageResizer::class);
        $files = app(FileStore::class);
        $create = app(CreateRepresentation::class);
        $provenance = app(RecordProvenance::class);

        $created = [];
        $skipped = [];

        foreach ($run->payload['tiers'] ?? [] as $slug) {
            $tier = QualityTier::findBySlug((string) $slug);

            if ($tier === null || $tier->pixels === null || $tier->pixels >= $sourcePixels) {
                $skipped[] = ['tier' => $slug, 'reason' => 'not smaller than the source'];

                continue;
            }

            $exists = Representation::query()->forKey($source->variant, $source->target, $tier)->approved()->exists();

            if ($exists) {
                $skipped[] = ['tier' => $slug, 'reason' => 'approved tier already exists'];

                continue;
            }

            $outputs = [];
            $inputs = [];

            foreach ($source->representationFiles as $representationFile) {
                $file = $representationFile->file;

                if (! $file->isImage()) {
                    continue;
                }

                [$bytes] = $resizer->fit($file->contents(), $tier->pixels, $file->mime_type);
                $name = sprintf('%s_%s_%s.%s', $source->variant->code, $representationFile->role->slug, $tier->slug, $file->extension ?? 'png');
                $outputs[$representationFile->role->slug] = $files->store($bytes, $name, $file->mime_type);
                $inputs[] = [$file, $representationFile->role->slug];
            }

            if ($outputs === []) {
                $skipped[] = ['tier' => $slug, 'reason' => 'no image maps to resize'];

                continue;
            }

            $representation = $create->handle($source->variant, $source->target, $tier, $outputs, $source->kind, $run->actor, [
                'downscaled_from_representation_id' => $source->getKey(),
                'downscaled_from_quality' => $source->quality->slug,
                'worker_run' => $run->uuid,
            ]);

            if ($source->isApproved()) {
                $representation->forceFill([
                    'review_state' => ReviewState::Approved,
                    'reviewed_at' => now(),
                    'notes' => 'Deterministic downscale of an approved set.',
                ])->save();
            }

            $provenance->handle(
                $representation,
                'downscaled',
                static::class,
                inputs: $inputs,
                outputs: $this->pairs($outputs),
                tool: 'opal downscale worker ('.$resizer->tool().')',
                toolVersion: ImageResizer::VERSION,
                parameters: ['tier' => $tier->slug, 'pixels' => $tier->pixels, 'source_pixels' => $sourcePixels],
                jobId: $run->uuid,
            );

            $created[] = ['tier' => $tier->slug, 'representation_id' => $representation->getKey(), 'files' => count($outputs)];
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * @param  array<string, File>  $files
     * @return list<array{0: File, 1: string|null}>
     */
    private function pairs(array $files): array
    {
        $pairs = [];

        foreach ($files as $role => $file) {
            $pairs[] = [$file, $role];
        }

        return $pairs;
    }

    private function largestEdge(Representation $representation): int
    {
        $largest = 0;

        foreach ($representation->representationFiles as $representationFile) {
            $largest = max($largest, (int) $representationFile->file->width_px, (int) $representationFile->file->height_px);
        }

        return $largest ?: 1;
    }
}
