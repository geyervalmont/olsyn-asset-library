<?php

namespace App\Library\Packaging;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Runs usd-toolbox as a subprocess.
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
        private readonly int $descriptionCeiling = 8388608,
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

        // The binary prints "usd-toolbox 0.1.0". Recording that whole line as a
        // version leaves every package saying "usd-toolbox usd-toolbox 0.1.0",
        // since the name is stored beside it.
        $reported = trim($process->getOutput());

        return preg_match('/^\S+\s+(v?\d[^\s]*)$/', $reported, $matches) === 1
            ? $matches[1]
            : $reported;
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

            $this->assertNothingInlined($output, $request);

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
     * Refuse a package whose scene description has swallowed its own textures.
     *
     * A USDZ is a scene description plus images. The description is text and
     * should be kilobytes; if it is megabytes, something has been serialised
     * into it that belongs in a file — which is exactly what happened when
     * provenance embedded raw image bytes as JSON integer arrays and made every
     * package four times its proper size.
     *
     * Checked on the description rather than on the package total, because the
     * total legitimately varies with the image codec and would false-alarm on a
     * lossless map set.
     */
    private function assertNothingInlined(string $package, BuildRequest $request): void
    {
        $archive = new ZipArchive;

        if ($archive->open($package) !== true) {
            throw new RuntimeException("The package built for [{$request->variantCode}] is not readable as an archive.");
        }

        $described = 0;

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $entry = $archive->statIndex($index);

            if ($entry === false) {
                continue;
            }

            if (in_array(strtolower(pathinfo((string) $entry['name'], PATHINFO_EXTENSION)), ['usda', 'usdc', 'json'], true)) {
                $described += (int) $entry['size'];
            }
        }

        $archive->close();

        if ($described > $this->descriptionCeiling) {
            throw new RuntimeException(sprintf(
                'The package for [%s] carries %s of scene description, over the %s ceiling. '
                .'Something is being serialised into the description instead of stored as a file.',
                $request->variantCode,
                $this->humanBytes($described),
                $this->humanBytes($this->descriptionCeiling),
            ));
        }
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1).' MB'
            : round($bytes / 1024).' KB';
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
