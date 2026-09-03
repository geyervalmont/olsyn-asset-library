<?php

namespace App\Library\Drives;

use App\Models\Drive;
use App\Models\File;
use App\Models\Material;
use App\Models\MaterialVersion;
use App\Models\Representation;
use App\Models\RepresentationFile;
use App\Models\Variant;

/**
 * Projects a drive's visible, published materials into a PrismFS namespace
 * manifest. Paths are readable and stable; objects are the files pinned by
 * each material's current version.
 *
 *   /materials/Carpet/Academix/Ashen/revit/CPT-TARKETT-ACADEMIX-ASHEN_base_color.png
 */
class DriveNamespace
{
    public const MANIFEST_VERSION = 1;

    /**
     * @return list<array{path: string, object: array{bucket: string, key: string, size: int, version: string|null}, file_id: int, variant: string, target: string, quality: string, role: string, sha256: string, mime_type: string}>
     */
    public function entries(Drive $drive): array
    {
        /** @var list<array{path: string, object: array{bucket: string, key: string, size: int, version: string|null}, file_id: int, variant: string, target: string, quality: string, role: string, sha256: string, mime_type: string}> $entries */
        $entries = [];

        $materials = Material::query()
            ->visibleToDrive($drive)
            ->whereNotNull('current_version_id')
            ->with(['category', 'currentVersion'])
            ->orderBy('code')
            ->get();

        foreach ($materials as $material) {
            $version = $material->currentVersion;

            if ($version === null) {
                continue;
            }

            foreach ($this->pinnedRepresentations($version, $drive) as $representation) {
                $variant = $representation->variant;
                $directory = implode('/', array_map($this->component(...), [
                    $material->category->name,
                    $material->name,
                    $variant->name,
                    $representation->target->slug,
                ]));

                foreach ($representation->representationFiles as $representationFile) {
                    $entries[] = [
                        'path' => $drive->root_path.'/'.$directory.'/'.$this->fileName($variant->code, $representationFile),
                        'object' => $this->object($representationFile->file),
                        'file_id' => $representationFile->file->getKey(),
                        'variant' => $variant->code,
                        'target' => $representation->target->slug,
                        'quality' => $representation->quality->slug,
                        'role' => $representationFile->role->slug,
                        'sha256' => $representationFile->file->sha256,
                        'mime_type' => $representationFile->file->mime_type,
                    ];
                }
            }
        }

        usort($entries, fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $entries;
    }

    /**
     * @return array{version: int, files: list<array{path: string, object: array{bucket: string, key: string, size: int, version: string|null}}>}
     */
    public function manifest(Drive $drive): array
    {
        $files = array_map(fn (array $entry): array => ['path' => $entry['path'], 'object' => $entry['object']], $this->entries($drive));

        return ['version' => self::MANIFEST_VERSION, 'files' => $files];
    }

    /**
     * The entries a drive projects for one variant.
     *
     * @return list<array{path: string, object: array{bucket: string, key: string, size: int, version: string|null}, file_id: int, variant: string, target: string, quality: string, role: string, sha256: string, mime_type: string}>
     */
    public function entriesForVariant(Drive $drive, Variant $variant): array
    {
        return array_values(array_filter(
            $this->entries($drive),
            fn (array $entry): bool => $entry['variant'] === $variant->code,
        ));
    }

    public function toYaml(Drive $drive): string
    {
        $lines = ['version: '.self::MANIFEST_VERSION, 'files:'];
        $entries = $this->entries($drive);

        if ($entries === []) {
            $lines[1] = 'files: []';
        }

        foreach ($entries as $entry) {
            $lines[] = '  - path: '.$this->quote($entry['path']);
            $lines[] = '    object:';
            $lines[] = '      bucket: '.$this->quote($entry['object']['bucket']);
            $lines[] = '      key: '.$this->quote($entry['object']['key']);
            $lines[] = '      size: '.$entry['object']['size'];
            $lines[] = '      version: '.($entry['object']['version'] === null ? 'null' : $this->quote($entry['object']['version']));
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return iterable<Representation>
     */
    private function pinnedRepresentations(MaterialVersion $version, Drive $drive): iterable
    {
        $query = $version->representations()->with(['variant', 'target', 'quality', 'representationFiles.file', 'representationFiles.role']);

        if ($drive->target_id !== null) {
            $query->where('representations.target_id', $drive->target_id);
        }

        return $query->get();
    }

    private function fileName(string $variantCode, RepresentationFile $representationFile): string
    {
        $file = $representationFile->file;
        $role = $representationFile->role->slug;
        $extension = $file->extension === null ? '' : '.'.$file->extension;

        return in_array($role, ['mdl', 'usd', 'rvt', 'package'], true)
            ? $variantCode.$extension
            : $variantCode.'_'.$role.$extension;
    }

    /**
     * @return array{bucket: string, key: string, size: int, version: string|null}
     */
    private function object(File $file): array
    {
        $bucket = (string) (config("filesystems.disks.{$file->disk}.bucket") ?: config('opal.bucket'));

        return ['bucket' => $bucket, 'key' => $file->object_key, 'size' => $file->bytes, 'version' => null];
    }

    /**
     * One path component: no slashes, no control characters, never empty.
     */
    private function component(string $value): string
    {
        $component = trim((string) preg_replace('/[\/\\\\\x00-\x1F]+/', '-', $value));
        $component = trim($component, '. ');

        return $component === '' ? '_' : $component;
    }

    private function quote(string $value): string
    {
        return '"'.addcslashes($value, '"\\').'"';
    }
}
