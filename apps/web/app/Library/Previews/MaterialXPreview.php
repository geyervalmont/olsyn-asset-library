<?php

namespace App\Library\Previews;

use App\Models\Package;
use Illuminate\Cache\FileStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Disposable MaterialX preview derived from the verified canonical package. */
class MaterialXPreview
{
    public function key(Package $package): string
    {
        // Bump when the bundled toolbox or the preview contract changes.
        return 'materialx-preview:v1:'.$package->sha256;
    }

    public function contents(Package $package): string
    {
        return Cache::store('file')->remember($this->key($package), now()->addDays(30), function () use ($package): string {
            /** @var FileStore $store */
            $store = Cache::store('file')->getStore();

            return $store->lock($this->key($package).':building', 100)->block(5, function () use ($package): string {
                $cached = Cache::store('file')->get($this->key($package));

                if (is_string($cached)) {
                    return $cached;
                }
                $contents = $this->build($package);
                Cache::store('file')->put($this->key($package), $contents, now()->addDays(30));

                return $contents;
            });
        });
    }

    private function build(Package $package): string
    {
        $binary = (string) config('opal.toolbox_bin');
        if (! is_file($binary) || ! is_executable($binary)) {
            throw new RuntimeException('MaterialX converter is unavailable.');
        }
        if ($package->bytes > 512 * 1024 * 1024) {
            throw new RuntimeException('This package exceeds the interactive preview limit.');
        }
        $workspace = storage_path('app/materialx-preview/'.bin2hex(random_bytes(12)));
        if (! mkdir($workspace, 0700, true) && ! is_dir($workspace)) {
            throw new RuntimeException('Cannot create MaterialX preview workspace.');
        }
        $input = $workspace.'/source.usdz';
        $output = $workspace.'/preview.json';
        try {
            $stream = Storage::disk(config('opal.packages_disk'))->readStream($package->object_key);
            if (! is_resource($stream)) {
                throw new RuntimeException('Cannot read the canonical package.');
            }
            $handle = fopen($input, 'wb');
            if ($handle === false) {
                fclose($stream);
                throw new RuntimeException('Cannot stage the canonical package.');
            }
            try {
                stream_copy_to_stream($stream, $handle, 512 * 1024 * 1024 + 1);
            } finally {
                fclose($handle);
                fclose($stream);
            }
            if (hash_file('sha256', $input) !== $package->sha256 || filesize($input) !== $package->bytes) {
                throw new RuntimeException('Canonical package integrity check failed.');
            }
            $process = new Process([$binary, 'export', '--input', $input, '--target', 'materialx-preview', '--tier', 'preview', '--output', $output]);
            $process->setTimeout(90);
            $process->mustRun();
            if (! is_file($output) || filesize($output) > 96 * 1024 * 1024) {
                throw new RuntimeException('MaterialX preview exceeds the response limit.');
            }
            $contents = file_get_contents($output);
            if ($contents === false) {
                throw new RuntimeException('Cannot read MaterialX preview.');
            }
            $bundle = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($bundle) || ($bundle['schema'] ?? null) !== 'usd-toolbox.materialx-preview.v1'
                || ! is_string($bundle['document'] ?? null) || ! is_array($bundle['assets'] ?? null)
                || ! is_array($bundle['material_names'] ?? null)) {
                throw new RuntimeException('Invalid MaterialX preview response.');
            }

            return $contents;
        } finally {
            @unlink($input);
            @unlink($output);
            @rmdir($workspace);
        }
    }
}
