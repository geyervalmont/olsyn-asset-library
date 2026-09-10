<?php

namespace App\Jobs;

use App\Actions\Provenance\RecordProvenance;
use App\Actions\Representations\CreateRepresentation;
use App\Library\FileStore;
use App\Library\Procedural\ProceduralBaker;
use App\Models\Definition;
use App\Models\File;
use App\Models\Representation;
use App\Models\User;
use App\Models\WorkerRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Bake a stored recipe into an immutable candidate PBR representation. */
final class BakeProceduralMaterial extends TrackedJob
{
    public static function type(): string
    {
        return 'bake_procedural_material';
    }

    public static function forDefinition(Definition $definition, ?User $actor = null, bool $force = false): WorkerRun
    {
        return self::launch(['definition_id' => $definition->getKey(), 'force' => $force], $definition->variant, $actor, 'materials');
    }

    /** @return array<string, mixed> */
    protected function execute(WorkerRun $run): array
    {
        $definition = Definition::query()->with('variant.material')->findOrFail((int) ($run->payload['definition_id'] ?? 0));
        $force = (bool) ($run->payload['force'] ?? false);

        if (! $force && ! $definition->isStale()) {
            return ['skipped' => 'definition is current'];
        }

        $baker = app(ProceduralBaker::class);

        if (! $baker->available()) {
            throw new RuntimeException('No USD toolbox is available to bake this procedural material.');
        }

        $bake = $baker->bake($definition->toolboxDefinition());
        $files = [];

        foreach ($bake->assets as $asset) {
            $files[$asset->role] = app(FileStore::class)->store(
                $asset->contents,
                $definition->variant->code.'_'.$asset->filename,
                $asset->mimeType,
            );
        }

        return DB::transaction(function () use ($definition, $bake, $files, $run): array {
            $definition->forceFill(['generator_version' => $bake->generatorVersion])->save();
            $applicationDigest = $definition->digest();

            if (! (bool) ($run->payload['force'] ?? false)) {
                $existing = Representation::query()
                    ->where('variant_id', $definition->variant_id)
                    ->where('metadata->procedural->definition_digest', $applicationDigest)
                    ->first();

                if ($existing !== null) {
                    $definition->markBaked();

                    return ['skipped' => 'candidate already exists', 'representation_id' => $existing->getKey()];
                }
            }

            $representation = app(CreateRepresentation::class)->handle(
                $definition->variant,
                'pbr',
                max($bake->widthPx, $bake->heightPx),
                $files,
                createdBy: $run->actor,
                metadata: [
                    'normal_convention' => 'opengl',
                    'procedural' => [
                        'definition_id' => $definition->getKey(),
                        'definition_digest' => $applicationDigest,
                        'toolbox_digest' => $bake->definitionDigest,
                        'generator' => $bake->generator,
                        'generator_version' => $bake->generatorVersion,
                        'physical_size_mm' => [$bake->widthMm, $bake->heightMm],
                        'worker_run' => $run->uuid,
                    ],
                ],
                notes: 'Procedural candidate. Review before publishing.',
            );

            app(RecordProvenance::class)->handle(
                $representation,
                'generated',
                static::class,
                outputs: $this->pairs($files),
                tool: 'usd-toolbox',
                toolVersion: $bake->generatorVersion,
                parameters: [
                    'definition_id' => $definition->getKey(),
                    'definition_digest' => $applicationDigest,
                    'toolbox_digest' => $bake->definitionDigest,
                    'generator' => $bake->generator,
                ],
                jobId: $run->uuid,
                notes: 'Deterministic procedural bake; candidate approval is required.',
            );

            $definition->markBaked();

            return [
                'definition_id' => $definition->getKey(),
                'representation_id' => $representation->getKey(),
                'files' => collect($files)->map(fn (File $file): int => (int) $file->getKey())->all(),
                'roles' => array_keys($files),
                'definition_digest' => $applicationDigest,
                'toolbox_digest' => $bake->definitionDigest,
            ];
        });
    }

    /**
     * @param  array<string, File>  $files
     * @return list<array{0: File, 1: string}>
     */
    private function pairs(array $files): array
    {
        $pairs = [];

        foreach ($files as $role => $file) {
            $pairs[] = [$file, $role];
        }

        return $pairs;
    }
}
