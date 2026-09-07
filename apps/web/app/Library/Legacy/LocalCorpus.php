<?php

namespace App\Library\Legacy;

/**
 * The corpus as a folder tree on this machine.
 */
class LocalCorpus implements CorpusSource
{
    public function __construct(private readonly string $root) {}

    public function has(string $relative): bool
    {
        return is_file($this->path($relative));
    }

    public function size(string $relative): int
    {
        return (int) filesize($this->path($relative));
    }

    public function mtime(string $relative): int
    {
        return (int) filemtime($this->path($relative));
    }

    public function key(string $relative): string
    {
        return $this->path($relative);
    }

    public function withLocalFile(string $relative, callable $callback): mixed
    {
        return $callback($this->path($relative));
    }

    public function describe(): string
    {
        return $this->root;
    }

    private function path(string $relative): string
    {
        return $this->root.'/'.str_replace('\\', '/', $relative);
    }
}
