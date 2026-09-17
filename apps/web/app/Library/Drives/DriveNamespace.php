<?php

namespace App\Library\Drives;

use App\Models\Drive;
use App\Models\File;
use App\Models\Material;
use App\Models\Package;
use App\Models\PackageDerivative;
use App\Models\PackageDerivativeFile;
use App\Models\Variant;

/**
 * Projects a drive's visible, published package derivatives into PrismFS.
 * The USDZ pinned by the material version is the source of truth; every file
 * here is a reproducible consumer cache carrying that package's hash.
 *
 *   /materials/Carpet/Academix/Ashen/revit/2k/CPT-TARKETT-ACADEMIX-ASHEN_base_color.png
 */
class DriveNamespace
{
    public const MANIFEST_VERSION = 1;

    /**
     * @return list<array{path: string, object: array{bucket: string, key: string, size: int, version: string|null}, file_id: int, variant: string, target: string, quality: string, role: string, sha256: string, mime_type: string, source_package_sha256: string, converter: string, converter_version: string}>
     */
    public function entries(Drive $drive): array
    {
        /** @var list<array{path: string, object: array{bucket: string, key: string, size: int, version: string|null}, file_id: int, variant: string, target: string, quality: string, role: string, sha256: string, mime_type: string, source_package_sha256: string, converter: string, converter_version: string}> $entries */
        $entries = [];

        $materials = Material::query()
            ->visibleToDrive($drive)
            ->whereNotNull('current_version_id')
            ->with([
                'category',
                'currentVersion.packages.variant',
                'currentVersion.packages.derivatives.target',
                'currentVersion.packages.derivatives.quality',
                'currentVersion.packages.derivatives.derivativeFiles.file',
                'currentVersion.packages.derivatives.derivativeFiles.role',
            ])
            ->orderBy('code')
            ->get();

        foreach ($materials as $material) {
            $version = $material->currentVersion;

            if ($version === null) {
                continue;
            }

            foreach ($version->packages as $package) {
                $variant = $package->variant;

                foreach ($this->currentDerivatives($package, $drive) as $derivative) {
                    $directory = implode('/', array_map($this->component(...), [
                        $material->category->name,
                        $material->name,
                        $variant->name,
                        $derivative->target->slug,
                        $derivative->quality->slug,
                    ]));

                    foreach ($derivative->derivativeFiles as $derivativeFile) {
                        $entries[] = [
                            'path' => $drive->root_path.'/'.$directory.'/'.$this->fileName($variant->code, $derivativeFile),
                            'object' => $this->object($derivativeFile->file),
                            'file_id' => $derivativeFile->file->getKey(),
                            'variant' => $variant->code,
                            'target' => $derivative->target->slug,
                            'quality' => $derivative->quality->slug,
                            'role' => $derivativeFile->role->slug,
                            'sha256' => $derivativeFile->file->sha256,
                            'mime_type' => $derivativeFile->file->mime_type,
                            'source_package_sha256' => $derivative->source_sha256,
                            'converter' => $derivative->converter,
                            'converter_version' => $derivative->converter_version,
                        ];
                    }
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
     * @return list<array{path: string, object: array{bucket: string, key: string, size: int, version: string|null}, file_id: int, variant: string, target: string, quality: string, role: string, sha256: string, mime_type: string, source_package_sha256: string, converter: string, converter_version: string}>
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
     * Only the newest converter generation for each target and quality is
     * projected. Old cache generations remain addressable by their package for
     * audit and rollback, but never collide at a friendly drive path.
     *
     * @return iterable<PackageDerivative>
     */
    private function currentDerivatives(Package $package, Drive $drive): iterable
    {
        return $package->derivatives
            ->where('source_sha256', $package->sha256)
            ->when($drive->target_id !== null, fn ($derivatives) => $derivatives->where('target_id', $drive->target_id))
            ->sortByDesc(fn (PackageDerivative $derivative): array => [$derivative->built_at->getTimestamp(), $derivative->getKey()])
            ->unique(fn (PackageDerivative $derivative): string => $derivative->target_id.':'.$derivative->quality_tier_id)
            ->values();
    }

    private function fileName(string $variantCode, PackageDerivativeFile $derivativeFile): string
    {
        $file = $derivativeFile->file;
        $role = $derivativeFile->role->slug;
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
