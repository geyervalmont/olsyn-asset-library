<?php

namespace App\Library\Derivatives;

use App\Models\Package;
use App\Models\QualityTier;
use App\Models\Target;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use ZipArchive;

/** Runs usd-toolbox against the canonical package and reads its target export. */
class ToolboxPackageDerivativeBuilder implements PackageDerivativeBuilder
{
    public function __construct(
        private readonly string $binary,
        private readonly string $packagesDisk,
        private readonly int $timeout = 900,
    ) {}

    public function name(): string
    {
        return 'usd-toolbox';
    }

    public function version(): string
    {
        $process = new Process([$this->binary, '--version']);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            return 'unknown';
        }

        $reported = trim($process->getOutput());

        return preg_match('/^\S+\s+(v?\d[^\s]*)$/', $reported, $matches) === 1
            ? $matches[1]
            : $reported;
    }

    public function available(): bool
    {
        return is_file($this->binary) && is_executable($this->binary);
    }

    public function supports(Target $target): bool
    {
        return in_array($target->slug, ['revit', 'omniverse'], true);
    }

    public function build(Package $package, Target $target, QualityTier $quality): BuiltPackageDerivative
    {
        if (! $this->supports($target)) {
            throw new RuntimeException("USD Toolbox cannot derive the [{$target->slug}] target from a package.");
        }

        $workspace = $this->workspace($package, $target, $quality);
        $input = $workspace.'/source.usdz';
        $output = $workspace.'/output.'.($target->slug === 'revit' ? 'zip' : 'usdz');
        $report = $workspace.'/report.json';

        try {
            $this->stage($package, $input);

            $process = new Process([
                $this->binary, 'export',
                '--input', $input,
                '--target', $target->slug,
                '--tier', $quality->slug,
                '--output', $output,
                '--report', $report,
            ]);
            $process->setTimeout($this->timeout);

            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                throw new RuntimeException("Deriving [{$package->variant->code}] for [{$target->slug}:{$quality->slug}] exceeded {$this->timeout}s.");
            }

            if (! $process->isSuccessful()) {
                throw new RuntimeException(
                    "Deriving [{$package->variant->code}] for [{$target->slug}:{$quality->slug}] failed: ".trim($process->getErrorOutput() ?: $process->getOutput())
                );
            }

            if (! is_file($output)) {
                throw new RuntimeException('The USD toolbox reported success but wrote no consumer artifact.');
            }

            $reportedLosses = $this->report($report)['losses'] ?? [];
            /** @var list<array<string, mixed>> $losses */
            $losses = [];

            if (is_array($reportedLosses)) {
                foreach ($reportedLosses as $loss) {
                    if (is_array($loss)) {
                        $losses[] = $loss;
                    }
                }
            }

            $assets = $target->slug === 'revit'
                ? $this->revitAssets($package, $output)
                : [$this->omniverseAsset($package, $output)];

            return new BuiltPackageDerivative($assets, $losses);
        } finally {
            foreach ([$input, $output, $report] as $path) {
                @unlink($path);
            }
            @rmdir($workspace);
        }
    }

    private function stage(Package $package, string $destination): void
    {
        $stream = $this->disk()->readStream($package->object_key);

        if (! is_resource($stream)) {
            throw new RuntimeException("Cannot read canonical package [{$package->object_key}].");
        }

        $handle = fopen($destination, 'wb');

        if ($handle === false) {
            fclose($stream);
            throw new RuntimeException("Cannot stage canonical package at [{$destination}].");
        }

        stream_copy_to_stream($stream, $handle);
        fclose($handle);
        fclose($stream);

        $actualHash = hash_file('sha256', $destination);
        $actualBytes = filesize($destination);

        if ($actualHash !== $package->sha256 || $actualBytes !== $package->bytes) {
            throw new RuntimeException("Canonical package [{$package->getKey()}] does not match its recorded hash and size.");
        }
    }

    /** @return list<DerivedAsset> */
    private function revitAssets(Package $package, string $path): array
    {
        $archive = new ZipArchive;

        if ($archive->open($path) !== true) {
            throw new RuntimeException('The Revit export is not a readable ZIP archive.');
        }

        try {
            $manifestBytes = $archive->getFromName('revit-material.json');

            if (! is_string($manifestBytes)) {
                throw new RuntimeException('The Revit export has no revit-material.json contract.');
            }

            try {
                $manifest = json_decode($manifestBytes, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new RuntimeException('The Revit export manifest is invalid JSON.', previous: $error);
            }

            $materials = is_array($manifest['materials'] ?? null) ? $manifest['materials'] : [];
            $material = collect($materials)->firstWhere('id', $package->variant->code) ?? ($materials[0] ?? null);

            if (! is_array($material) || ! is_array($material['slots'] ?? null)) {
                throw new RuntimeException('The Revit export manifest has no material slots.');
            }

            $assets = [];

            foreach ($material['slots'] as $role => $slot) {
                if (! in_array($role, ['base_color', 'bump', 'glossiness'], true) || ! is_array($slot)) {
                    continue;
                }

                $entry = (string) ($slot['path'] ?? '');
                $this->assertSafeEntry($entry);
                $contents = $archive->getFromName($entry);

                if (! is_string($contents)) {
                    throw new RuntimeException("The Revit export is missing [{$entry}].");
                }

                $expected = (string) ($slot['sha256'] ?? '');

                if ($expected === '' || ! hash_equals($expected, hash('sha256', $contents))) {
                    throw new RuntimeException("The Revit export entry [{$entry}] failed its SHA-256 check.");
                }

                $extension = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
                $assets[] = new DerivedAsset(
                    role: $role,
                    contents: $contents,
                    name: $package->variant->code.'_'.$role.'.'.$extension,
                    mimeType: (string) ($slot['media_type'] ?? 'application/octet-stream'),
                    colourSpace: $role === 'base_color' ? 'srgb' : 'linear',
                );
            }

            if ($assets === []) {
                throw new RuntimeException('The Revit export contains no supported appearance slots.');
            }

            return $assets;
        } finally {
            $archive->close();
        }
    }

    private function omniverseAsset(Package $package, string $path): DerivedAsset
    {
        $contents = file_get_contents($path);

        if ($contents === false || $contents === '') {
            throw new RuntimeException('The Omniverse export is empty.');
        }

        return new DerivedAsset(
            role: 'usd',
            contents: $contents,
            name: $package->variant->code.'.usdz',
            mimeType: 'model/vnd.usdz+zip',
        );
    }

    private function assertSafeEntry(string $entry): void
    {
        if ($entry === '' || str_starts_with($entry, '/') || str_contains($entry, '\\') || in_array('..', explode('/', $entry), true)) {
            throw new RuntimeException("The Revit export contains an unsafe path [{$entry}].");
        }
    }

    /** @return array<string, mixed> */
    private function report(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        try {
            $report = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($report) ? $report : [];
    }

    private function workspace(Package $package, Target $target, QualityTier $quality): string
    {
        $path = storage_path('app/package-derivatives/'.substr($package->sha256, 0, 16).'-'.$target->slug.'-'.$quality->slug.'-'.bin2hex(random_bytes(4)));

        if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException("Cannot create derivative workspace [{$path}].");
        }

        return $path;
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->packagesDisk);
    }
}
