<?php

namespace App\Jobs;

use App\Actions\Provenance\RecordProvenance;
use App\Actions\Representations\CreateRepresentation;
use App\Actions\Representations\ReviewRepresentation;
use App\Enums\ReviewState;
use App\Library\FileStore;
use App\Library\Previews\SphereRenderer;
use App\Models\File;
use App\Models\Representation;
use App\Models\Target;
use App\Models\User;
use App\Models\Variant;
use App\Models\WorkerRun;

/**
 * Render a lit sphere swatch for a variant from its approved canonical maps.
 * The render is deterministic for a given set of inputs, so it is approved
 * on creation and skipped when one already exists for the same inputs.
 */
class RenderPreview extends TrackedJob
{
    public const SIZE = 512;

    public const MAP_ROLES = ['base_color', 'normal', 'roughness', 'metallic'];

    public static function type(): string
    {
        return 'render_preview';
    }

    public static function forVariant(Variant $variant, ?User $actor = null, bool $force = false): WorkerRun
    {
        return static::launch(['variant_id' => $variant->getKey(), 'force' => $force], $variant, $actor);
    }

    /**
     * The approved canonical set a render would use, smallest tier at or above the swatch size.
     */
    public static function sourceFor(Variant $variant): ?Representation
    {
        $canonical = Target::canonical();

        if ($canonical === null) {
            return null;
        }

        $candidates = Representation::query()
            ->where('variant_id', $variant->getKey())
            ->where('target_id', $canonical->getKey())
            ->approved()
            ->with(['quality', 'representationFiles.file', 'representationFiles.role'])
            ->get()
            ->filter(fn (Representation $representation): bool => $representation->fileFor('base_color')?->isImage() ?? false);

        $large = $candidates->filter(fn (Representation $r): bool => ($r->quality->pixels ?? PHP_INT_MAX) >= self::SIZE)->sortBy(fn (Representation $r): int => $r->quality->pixels ?? PHP_INT_MAX);

        return $large->first() ?? $candidates->sortByDesc(fn (Representation $r): int => $r->quality->pixels ?? 0)->first();
    }

    /**
     * Fingerprint of everything that determines the render's pixels.
     */
    public static function inputHash(Representation $source): string
    {
        $parts = [SphereRenderer::VERSION, json_encode(SphereRenderer::RIG), (string) self::SIZE];

        foreach (self::MAP_ROLES as $role) {
            $file = $source->fileFor($role);
            $parts[] = $role.':'.($file === null ? '-' : $file->sha256);
        }

        return hash('sha256', implode('|', $parts));
    }

    public static function renderedFor(Variant $variant, ?string $inputHash = null): ?Representation
    {
        return Representation::query()
            ->where('variant_id', $variant->getKey())
            ->where('target_id', Target::fromSlug('preview')->getKey())
            ->approved()
            ->when($inputHash !== null, fn ($query) => $query->where('metadata->render->input_hash', $inputHash))
            ->whereNotNull('metadata->render->input_hash')
            ->first();
    }

    protected function execute(WorkerRun $run): ?array
    {
        $variant = Variant::query()->findOrFail((int) ($run->payload['variant_id'] ?? 0));
        $source = static::sourceFor($variant);

        if ($source === null) {
            return ['skipped' => 'no approved canonical set with a base colour'];
        }

        $hash = static::inputHash($source);

        if (! ($run->payload['force'] ?? false) && ($existing = static::renderedFor($variant, $hash)) !== null) {
            return ['skipped' => 'already rendered for these inputs', 'representation_id' => $existing->getKey()];
        }

        $maps = [];
        $inputs = [];

        foreach (self::MAP_ROLES as $role) {
            $file = $source->fileFor($role);

            if ($file !== null && $file->isImage()) {
                $maps[$role] = $file->contents();
                $inputs[] = [$file, $role];
            }
        }

        /** @var array{base_color: string, normal?: string, roughness?: string, metallic?: string} $maps */
        $renderer = app(SphereRenderer::class);
        $started = hrtime(true);
        $png = $renderer->render($maps, self::SIZE);
        $renderMs = (hrtime(true) - $started) / 1e6;

        $output = app(FileStore::class)->store($png, sprintf('%s_preview.png', $variant->code), 'image/png');

        $representation = app(CreateRepresentation::class)->handle($variant, 'preview', 'preview', ['render' => $output], Representation::KIND_IMAGE, $run->actor, [
            'render' => ['input_hash' => $hash, 'source_representation_id' => $source->getKey(), 'renderer' => SphereRenderer::VERSION, 'worker_run' => $run->uuid],
        ]);

        app(ReviewRepresentation::class)->handle($representation, ReviewState::Approved, null, 'Deterministic render of an approved set.');

        app(RecordProvenance::class)->handle(
            $representation,
            'rendered',
            static::class,
            inputs: $inputs,
            outputs: [[$output, 'render']],
            tool: $renderer->tool(),
            toolVersion: SphereRenderer::VERSION,
            parameters: ['rig' => SphereRenderer::RIG, 'size' => self::SIZE, 'maps' => array_keys($maps), 'render_ms' => round($renderMs, 1)],
            jobId: $run->uuid,
        );

        return ['representation_id' => $representation->getKey(), 'file_id' => $output->getKey(), 'maps' => array_keys($maps), 'render_ms' => round($renderMs, 1)];
    }

    /**
     * Files that fed a render, for the provenance edges.
     *
     * @param  list<array{0: File, 1: string}>  $inputs
     * @return list<string>
     */
    public static function roles(array $inputs): array
    {
        return array_map(fn (array $pair): string => $pair[1], $inputs);
    }
}
