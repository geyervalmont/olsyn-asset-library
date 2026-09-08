<?php

namespace App\Library\Packaging;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs the materials toolbox as a subprocess.
 *
 * A subprocess rather than FFI: the toolbox is a separate project on its own
 * release cadence, and a crash in a foreign library would take the worker with
 * it. The cost is process startup, which is irrelevant beside reading a few
 * hundred megabytes of texture.
 *
 * The library does the I/O and the toolbox does the format work, which is what
 * keeps the same code usable from the browser: files are staged locally, the
 * manifest names them by path, and the toolbox never reaches for a bucket.
 */
class ToolboxPackageBuilder implements PackageBuilder
{
    public function __construct(
        private readonly string $binary,
        private readonly string $filesDisk,
        private readonly int $timeout = 900,
    ) {}

    public function name(): string
    {
        return 'materials-toolbox';
    }

    public function version(): string
    {
        $process = new Process([$this->binary, '--version']);
        $process->setTimeout(30);
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : 'unknown';
    }

    public function available(): bool
    {
        return is_file($this->binary) && is_executable($this->binary);
    }

    public function build(BuildRequest $request): BuiltPackage
    {
        if ($request->isEmpty()) {
            throw new RuntimeException("[{$request->variantCode}] has no canonical files to package.");
        }

        $workspace = $this->workspace($request);

        try {
            $manifest = $this->stage($request, $workspace);
            $output = $workspace.'/package.usdz';

            $process = new Process([
                $this->binary, 'build',
                '--manifest', $manifest,
                '--output', $output,
                '--report', $workspace.'/report.json',
            ]);
            $process->setTimeout($this->timeout);

            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                throw new RuntimeException("Packaging [{$request->variantCode}] exceeded {$this->timeout}s.");
            }

            if (! $process->isSuccessful()) {
                throw new RuntimeException(
                    "Packaging [{$request->variantCode}] failed: ".trim($process->getErrorOutput() ?: $process->getOutput())
                );
            }

            if (! is_file($output)) {
                throw new RuntimeException("The toolbox reported success but wrote no package for [{$request->variantCode}].");
            }

            $report = $this->report($workspace.'/report.json');
            $digest = hash_file('sha256', $output);

            if ($digest === false) {
                throw new RuntimeException("The package written for [{$request->variantCode}] could not be read back.");
            }

            return new BuiltPackage(
                path: $output,
                sha256: $digest,
                bytes: (int) filesize($output),
                tiers: $report['tiers'] ?? $request->tiers(),
                // Absent losses are not an empty list: a builder that did not
                // report is not a builder that lost nothing.
                losses: $report['losses'] ?? [],
                builder: $this->name(),
                builderVersion: $report['version'] ?? $this->version(),
            );
        } finally {
            $this->clean($workspace, keep: 'package.usdz');
        }
    }

    /**
     * Pull every source file to local disk and write the manifest beside them.
     * Paths in the manifest are relative to the workspace, so the same manifest
     * describes the same build wherever it runs.
     */
    private function stage(BuildRequest $request, string $workspace): string
    {
        $disk = $this->disk();
        $manifest = $request->toManifest();

        foreach ($request->channels as $role => $sources) {
            foreach ($sources as $tier => $source) {
                $local = 'sources/'.$source->sha256.'.'.pathinfo($source->objectKey, PATHINFO_EXTENSION);
                $target = $workspace.'/'.$local;

                if (! is_dir(dirname($target))) {
                    mkdir(dirname($target), 0775, true);
                }

                $stream = $disk->readStream($source->objectKey);

                if (! is_resource($stream)) {
                    throw new RuntimeException("Cannot read [{$source->objectKey}] for [{$request->variantCode}].");
                }

                $handle = fopen($target, 'wb');

                if ($handle === false) {
                    fclose($stream);

                    throw new RuntimeException("Cannot stage [{$source->objectKey}] to [{$target}].");
                }

                stream_copy_to_stream($stream, $handle);
                fclose($handle);
                fclose($stream);

                $manifest['channels'][$role][$tier]['path'] = $local;
            }
        }

        $path = $workspace.'/manifest.json';
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function report(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function workspace(BuildRequest $request): string
    {
        $path = storage_path('app/packaging/'.$request->variantCode.'-'.substr($request->digest(), 0, 12));

        if (! is_dir($path)) {
            mkdir($path, 0775, true);
        }

        return $path;
    }

    private function clean(string $workspace, string $keep): void
    {
        foreach (glob($workspace.'/sources/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($workspace.'/sources');
        @unlink($workspace.'/manifest.json');
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->filesDisk);
    }
}
