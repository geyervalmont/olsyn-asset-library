<?php

namespace App\Library\Legacy;

use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;

/**
 * The corpus staged on a filesystem disk, usually a bucket.
 *
 * Files are streamed to a temporary path so the rest of the ingest works the
 * same as it does locally, and nothing larger than one file is ever held.
 */
class DiskCorpus implements CorpusSource
{
    public function __construct(
        private readonly Filesystem $disk,
        private readonly string $name,
        private readonly string $prefix = '',
    ) {}

    public function has(string $relative): bool
    {
        return $this->disk->fileExists($this->path($relative));
    }

    public function size(string $relative): int
    {
        return (int) $this->disk->fileSize($this->path($relative));
    }

    public function mtime(string $relative): int
    {
        return (int) $this->disk->lastModified($this->path($relative));
    }

    public function key(string $relative): string
    {
        return $this->name.'://'.$this->path($relative);
    }

    public function withLocalFile(string $relative, callable $callback): mixed
    {
        $stream = $this->disk->readStream($this->path($relative));

        if (! is_resource($stream)) {
            throw new RuntimeException("Cannot read [{$this->key($relative)}] from the staged corpus.");
        }

        $temporary = tempnam(sys_get_temp_dir(), 'opal-corpus-');

        if ($temporary === false) {
            fclose($stream);

            throw new RuntimeException('Cannot create a temporary file for the staged corpus.');
        }

        $handle = fopen($temporary, 'wb');

        if ($handle === false) {
            fclose($stream);
            @unlink($temporary);

            throw new RuntimeException("Cannot write the temporary file [{$temporary}].");
        }

        try {
            stream_copy_to_stream($stream, $handle);
        } finally {
            fclose($handle);
            fclose($stream);
        }

        try {
            return $callback($temporary);
        } finally {
            @unlink($temporary);
        }
    }

    public function describe(): string
    {
        return $this->name.'://'.($this->prefix !== '' ? $this->prefix : '');
    }

    private function path(string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);

        return $this->prefix === '' ? $relative : rtrim($this->prefix, '/').'/'.$relative;
    }
}
