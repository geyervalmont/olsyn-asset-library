<?php

namespace App\Library\Drives;

use App\Models\Drive;
use App\Models\File;
use App\Models\Material;
use App\Models\Package;
use App\Models\PackageDerivative;
use App\Models\PackageDerivativeFile;
use App\Models\User;
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
     * @return list<array{path: string, object: array{bucket: string, key: string, size: int, version: string|null}, file_id: int, variant: string, target: string, quality: string, role: string, sha256: string, mime_type: string, source_package_sha256: string, converter: string, converter_version: string, current?: bool, latest_cache?: bool, material_uuid?: string, variant_uuid?: string, material_version?: int, derivative_uuid?: string}>
     */
    public function entries(Drive $drive): array
    {
        if ($drive->path_layout === 'stable') {
            return $this->stableEntries($drive);
        }

        /** @var list<array{path: string, object: array{bucket: string, key: string, size: int, version: string|null}, file_id: int, variant: string, target: string, quality: string, role: string, sha256: string, mime_type: string, source_package_sha256: string, converter: string, converter_version: string, current?: bool, latest_cache?: bool, material_uuid?: string, variant_uuid?: string, material_version?: int, derivative_uuid?: string}> $entries */
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
     * @return list<array{path: string, object: array{bucket: string, key: string, size: int, version: string|null}, file_id: int, variant: string, target: string, quality: string, role: string, sha256: string, mime_type: string, source_package_sha256: string, converter: string, converter_version: string, current?: bool, latest_cache?: bool, material_uuid?: string, variant_uuid?: string, material_version?: int, derivative_uuid?: string}>
     */
    public function entriesForVariant(Drive $drive, Variant $variant, ?int $version = null): array
    {
        return array_values(array_filter(
            $this->entries($drive),
            fn (array $entry): bool => $entry['variant'] === $variant->code
                && ($version === null ? ($entry['current'] ?? true) : (($entry['material_version'] ?? null) === $version && ($entry['latest_cache'] ?? false))),
        ));
    }

    /**
     * Immutable paths retain every published version and converter generation.
     * Visibility is still evaluated now, so retention never bypasses revocation.
     *
     * @return list<array{path: string, object: array{bucket: string, key: string, size: int, version: string|null}, file_id: int, variant: string, target: string, quality: string, role: string, sha256: string, mime_type: string, source_package_sha256: string, converter: string, converter_version: string, current?: bool, latest_cache?: bool, material_uuid?: string, variant_uuid?: string, material_version?: int, derivative_uuid?: string}>
     */
    private function stableEntries(Drive $drive, ?User $user = null, ?Variant $variant = null, ?int $versionNumber = null): array
    {
        $entries = [];
        $materials = Material::query()
            ->when($user !== null, fn ($query) => $query->visibleTo($user), fn ($query) => $query->visibleToDrive($drive))
            ->when($variant !== null, fn ($query) => $query->whereKey($variant->material_id))
            ->with([
                'versions' => fn ($query) => $query->whereNotNull('published_at')->when($versionNumber !== null, fn ($query) => $query->where('number', $versionNumber)),
                'versions.packages.variant', 'versions.packages.derivatives.target',
                'versions.packages.derivatives.quality', 'versions.packages.derivatives.derivativeFiles.file',
                'versions.packages.derivatives.derivativeFiles.role',
            ])->get();
        foreach ($materials as $material) {
            foreach ($material->versions as $version) {
                foreach ($version->packages as $package) {
                    $current = collect($this->currentDerivatives($package, $drive))->pluck('id')->all();
                    foreach ($package->derivatives as $derivative) {
                        if ($derivative->source_sha256 !== $package->sha256
                            || ($drive->target_id !== null && $derivative->target_id !== $drive->target_id)) {
                            continue;
                        }
                        foreach ($derivative->derivativeFiles as $item) {
                            $file = $item->file;
                            $path = implode('/', [$drive->root_path, 'by-id', $material->uuid, $package->variant->uuid,
                                'v'.$version->number, $derivative->target->slug, $derivative->quality->slug,
                                $derivative->uuid, $item->role->slug.($file->extension ? '.'.$file->extension : '')]);
                            $entries[] = [
                                'path' => $path, 'object' => $this->object($file), 'file_id' => $file->id,
                                'variant' => $package->variant->code, 'target' => $derivative->target->slug,
                                'quality' => $derivative->quality->slug, 'role' => $item->role->slug,
                                'sha256' => $file->sha256, 'mime_type' => $file->mime_type,
                                'source_package_sha256' => $package->sha256, 'converter' => $derivative->converter,
                                'converter_version' => $derivative->converter_version,
                                'material_uuid' => $material->uuid, 'variant_uuid' => $package->variant->uuid,
                                'material_version' => $version->number, 'derivative_uuid' => $derivative->uuid,
                                'latest_cache' => in_array($derivative->id, $current, true),
                                'current' => $material->current_version_id === $version->id && in_array($derivative->id, $current, true),
                            ];
                        }
                    }
                }
            }
        }
        usort($entries, fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return $entries;
    }

    /**
     * A personal drive uses the user's grants, never a shared drive token.
     *
     * @return list<array<string, mixed>>
     */
    public function entriesForUser(User $user): array
    {
        return $this->stableEntries(new Drive(['root_path' => '/materials', 'path_layout' => 'stable']), $user);
    }

    /** Canonical packages use the exact same immutable paths as the Omniverse resolver.
     * @return list<array<string, mixed>>
     */
    public function canonicalEntriesForUser(User $user): array
    {
        $files = [];
        $materials = Material::query()->visibleTo($user)->with([
            'versions' => fn ($query) => $query->whereNotNull('published_at'), 'versions.packages.variant',
        ])->get();
        foreach ($materials as $material) {
            foreach ($material->versions as $version) {
                foreach ($version->packages as $package) {
                    $files[] = [
                        'path' => '/materials/by-id/'.$material->uuid.'/'.$package->variant->uuid.'/v'.$version->number.'/canonical/'.$package->sha256.'.usdz',
                        'bytes' => $package->bytes, 'sha256' => $package->sha256,
                        'content_url' => route('api.drive.package', ['package' => $package->id], false),
                        'material_uuid' => $material->uuid, 'variant_uuid' => $package->variant->uuid,
                        'current' => $material->current_version_id === $version->id,
                        'material_version' => $version->number, 'target' => 'omniverse', 'quality' => 'canonical', 'role' => 'package',
                    ];
                }
            }
        }

        return $files;
    }

    /**
     * A readable view of the latest published files. The caller supplies only
     * current derivatives/packages; permanent references still use by-id.
     *
     * @param  list<array<string, mixed>>  $currentFiles
     * @return list<array<string, mixed>>
     */
    public function namedEntriesForUser(User $user, array $currentFiles): array
    {
        if ($currentFiles === []) {
            return [];
        }
        $materials = Material::query()->visibleTo($user)
            ->whereIn('uuid', array_unique(array_column($currentFiles, 'material_uuid')))
            ->with(['category', 'variants'])->get();
        $materialLabels = $this->browseLabels($materials->pluck('name', 'uuid')->all());
        $directories = [];
        foreach ($materials as $material) {
            $variantLabels = $this->browseLabels($material->variants->pluck('name', 'uuid')->all());
            foreach ($variantLabels as $uuid => $label) {
                $directories[$material->uuid][$uuid] = '/materials/by-name/'.$this->browseComponent($material->category->name)
                    .'/'.$materialLabels[$material->uuid].'/'.$label;
            }
        }
        $files = [];
        foreach ($currentFiles as $file) {
            $directory = $directories[$file['material_uuid']][$file['variant_uuid']] ?? null;
            if ($directory === null) {
                continue;
            }
            $suffix = $file['role'] === 'package' ? '/canonical/material.usdz'
                : '/'.$file['target'].'/'.$file['quality'].'/'.basename($file['path']);
            $files[] = array_replace($file, ['path' => $directory.$suffix]);
        }

        return $files;
    }

    /** @param array<string, string> $names
     * @return array<string, string>
     */
    private function browseLabels(array $names): array
    {
        $labels = array_map($this->browseComponent(...), $names);
        $keys = array_map(fn (string $label): string => mb_convert_case($label, MB_CASE_FOLD, 'UTF-8'), $labels);
        $counts = array_count_values($keys);
        foreach ($labels as $uuid => &$label) {
            // Full identity only for ambiguous names. Brackets are reserved in
            // browseComponent, so a literal name cannot impersonate this suffix.
            if ($counts[$keys[$uuid]] > 1) {
                $label .= ' ['.$uuid.']';
            }
        }

        return $labels;
    }

    private function browseComponent(string $value): string
    {
        $value = trim((string) preg_replace('/[\\\\\/\x00-\x1f\x7f<>:"|?*\[\]]+/u', '-', $value), '. ');
        $value = rtrim(mb_strcut($value, 0, 60, 'UTF-8'), '. ');
        if ($value === '') {
            return 'Unnamed';
        }
        if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $value)) {
            $value = '_'.$value;
        }

        return $value;
    }

    /** @return list<array<string, mixed>> */
    public function entriesForUserVariant(User $user, Variant $variant, ?int $version = null): array
    {
        $version ??= $variant->material->currentVersion?->number;
        if ($version === null) {
            return [];
        }

        return array_values(array_filter($this->stableEntries(
            new Drive(['root_path' => '/materials', 'path_layout' => 'stable']), $user, $variant, $version,
        ), fn (array $entry): bool => ($entry['variant_uuid'] ?? null) === $variant->uuid && ($entry['latest_cache'] ?? false)));
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
