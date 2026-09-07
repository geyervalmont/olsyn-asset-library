<?php

namespace App\Library\Legacy;

/**
 * Where the legacy corpus is being read from.
 *
 * The corpus is far too large to sit on a laptop, so the ingest reads it
 * either from a local folder tree or from a staging bucket. Files are handed
 * to the caller as a local path, whatever the source, so hashing and storing
 * stream rather than loading whole files into memory.
 */
interface CorpusSource
{
    public function has(string $relative): bool;

    public function size(string $relative): int;

    /** Unix timestamp of the source file, used to skip unchanged files. */
    public function mtime(string $relative): int;

    /** Stable identity for the ingest ledger. */
    public function key(string $relative): string;

    /**
     * Run the callback with a local path to the file, cleaning up afterwards
     * if the file had to be fetched.
     *
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T
     */
    public function withLocalFile(string $relative, callable $callback): mixed;

    /** Human-readable description of the root, for messages. */
    public function describe(): string;
}
