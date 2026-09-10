<?php

namespace App\Actions\Materials;

use App\Actions\Provenance\RecordProvenance;
use App\Actions\Representations\CreateRepresentation;
use App\Library\FileStore;
use App\Models\File;
use App\Models\Representation;
use App\Models\Source;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** The shared new-material and improve-existing upload pipeline. */
final class ImportMaterialMaps
{
    /**
     * @param  array<string, UploadedFile|null>  $uploads
     */
    public function handle(
        Variant $variant,
        array $uploads,
        ?User $actor = null,
        ?Source $source = null,
        string $normalConvention = 'opengl',
        ?string $notes = null,
    ): ?Representation {
        $uploads = array_filter($uploads, fn (mixed $upload): bool => $upload instanceof UploadedFile);

        if ($uploads === []) {
            return null;
        }

        if (isset($uploads['normal']) && ! in_array($normalConvention, ['opengl', 'directx'], true)) {
            throw new InvalidArgumentException('Normal convention must be explicit for normal maps.');
        }

        $stored = [];

        foreach ($uploads as $role => $upload) {
            $stored[$role] = app(FileStore::class)->store($upload, $upload->getClientOriginalName());
        }

        $reference = $stored['base_color'] ?? reset($stored);
        $pixels = max((int) ($reference->width_px ?? 0), (int) ($reference->height_px ?? 0)) ?: 1024;

        return DB::transaction(function () use ($variant, $stored, $pixels, $actor, $source, $normalConvention, $notes): Representation {
            $representation = app(CreateRepresentation::class)->handle(
                $variant,
                'pbr',
                $pixels,
                $stored,
                createdBy: $actor,
                metadata: isset($stored['normal']) ? ['normal_convention' => $normalConvention] : null,
                notes: $notes ?? 'Uploaded candidate. Review before publishing.',
            );

            app(RecordProvenance::class)->handle(
                $variant,
                'uploaded',
                $actor,
                outputs: $this->pairs($stored),
                source: $source,
                notes: $notes ?? 'Uploaded through Material Import as a candidate.',
            );

            return $representation;
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
