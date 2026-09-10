<?php

namespace App\Library\Procedural;

use FilesystemIterator;
use Illuminate\Support\Str;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/** Filesystem/process adapter for the shared usd-toolbox procedural engine. */
final class ToolboxProceduralBaker implements ProceduralBaker
{
    /** @var list<string> */
    private const ROLES = ['base_color', 'normal', 'roughness', 'height', 'metallic', 'hatch_svg', 'hatch_pat'];

    public function __construct(
        private readonly string $binary,
        private readonly int $timeout = 900,
    ) {}

    public function available(): bool
    {
        return is_file($this->binary) && is_executable($this->binary);
    }

    public function bake(array $definition): ProceduralBake
    {
        $workspace = storage_path('app/procedural/'.Str::uuid());
        $outputs = $workspace.'/outputs';

        if (! mkdir($outputs, 0775, true) && ! is_dir($outputs)) {
            throw new RuntimeException("Cannot create procedural workspace [{$workspace}].");
        }

        try {
            $definitionPath = $workspace.'/definition.json';
            $reportPath = $workspace.'/report.json';

            try {
                $encoded = json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('The procedural definition cannot be encoded: '.$exception->getMessage(), previous: $exception);
            }

            file_put_contents($definitionPath, $encoded);

            $process = new Process([
                $this->binary, 'bake-procedural',
                '--definition', $definitionPath,
                '--output-dir', $outputs,
                '--report', $reportPath,
            ]);
            $process->setTimeout($this->timeout);

            try {
                $process->run();
            } catch (ProcessTimedOutException $exception) {
                throw new RuntimeException("Procedural bake exceeded {$this->timeout}s.", previous: $exception);
            }

            if (! $process->isSuccessful()) {
                throw new RuntimeException('Procedural bake failed: '.trim($process->getErrorOutput() ?: $process->getOutput()));
            }

            $report = $this->readReport($reportPath);
            $assets = [];

            foreach ($report['assets'] as $item) {
                $assets[] = $this->readAsset($item, $outputs);
            }

            return new ProceduralBake(
                generator: $this->string($report, 'generator'),
                generatorVersion: $this->string($report, 'generator_version'),
                definitionDigest: $this->digest($report, 'definition_digest'),
                widthPx: $this->integer($report, 'width_px'),
                heightPx: $this->integer($report, 'height_px'),
                widthMm: $this->number($report, 'width_mm'),
                heightMm: $this->number($report, 'height_mm'),
                assets: $assets,
            );
        } finally {
            $this->clean($workspace);
        }
    }

    /** @return array<string, mixed> */
    private function readReport(string $path): array
    {
        $contents = is_file($path) ? file_get_contents($path) : false;

        if (! is_string($contents)) {
            throw new RuntimeException('The toolbox reported success but wrote no procedural bake report.');
        }

        try {
            $report = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The toolbox wrote an invalid procedural bake report.', previous: $exception);
        }

        if (! is_array($report) || ($report['schema'] ?? null) !== 'usd-toolbox.procedural-bake.v1' || ! is_array($report['assets'] ?? null)) {
            throw new RuntimeException('The toolbox wrote an unsupported procedural bake report.');
        }

        return $report;
    }

    /** @param array<string, mixed> $item */
    private function readAsset(array $item, string $outputs): ProceduralAsset
    {
        $role = $this->string($item, 'role');
        $filename = $this->string($item, 'path');

        if (! in_array($role, self::ROLES, true) || basename($filename) !== $filename) {
            throw new RuntimeException("The toolbox reported an unsafe procedural output [{$filename}].");
        }

        $contents = file_get_contents($outputs.'/'.$filename);

        if (! is_string($contents)) {
            throw new RuntimeException("The toolbox did not write procedural output [{$filename}].");
        }

        $digest = $this->digest($item, 'sha256');

        if (! hash_equals($digest, hash('sha256', $contents)) || strlen($contents) !== $this->integer($item, 'bytes')) {
            throw new RuntimeException("Procedural output [{$filename}] does not match its report.");
        }

        return new ProceduralAsset(
            role: $role,
            filename: $filename,
            extension: $this->string($item, 'extension'),
            mimeType: $this->string($item, 'media_type'),
            sha256: $digest,
            contents: $contents,
        );
    }

    /** @param array<string, mixed> $values */
    private function string(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Procedural report field [{$key}] is invalid.");
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function digest(array $values, string $key): string
    {
        $value = $this->string($values, $key);

        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new RuntimeException("Procedural report digest [{$key}] is invalid.");
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function integer(array $values, string $key): int
    {
        $value = $values[$key] ?? null;

        if (! is_int($value) || $value < 0) {
            throw new RuntimeException("Procedural report field [{$key}] is invalid.");
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function number(array $values, string $key): float
    {
        $value = $values[$key] ?? null;

        if (! is_int($value) && ! is_float($value)) {
            throw new RuntimeException("Procedural report field [{$key}] is invalid.");
        }

        return (float) $value;
    }

    private function clean(string $workspace): void
    {
        if (! is_dir($workspace)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($workspace);
    }
}
