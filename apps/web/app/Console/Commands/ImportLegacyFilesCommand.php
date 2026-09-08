<?php

namespace App\Console\Commands;

use App\Library\Legacy\DiskCorpus;
use App\Library\Legacy\LegacyImporter;
use App\Library\Legacy\LocalCorpus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

/**
 * The file stage of the legacy import, built to run over the full corpus:
 * every file goes through a ledger, so the command can be stopped and rerun
 * and only touches what changed or failed.
 */
class ImportLegacyFilesCommand extends Command
{
    protected $signature = 'opal:import:legacy-files
        {--source= : Root of the legacy folder tree (defaults to storage/app/legacy/files)}
        {--disk= : Read the corpus from this filesystem disk instead of a local folder}
        {--prefix= : Path within the disk that holds the corpus}
        {--database=storage/app/legacy/material_assets.sqlite : Path to the legacy SQLite database}
        {--database-disk= : Fetch the database from this filesystem disk before reading it}
        {--limit= : Consider at most this many products}
        {--only= : A single product by legacy slug}
        {--dry-run : Report what would be stored without reading file contents}
        {--fresh : Remove everything a previous import created before importing}';

    protected $description = 'Resumable ingest of the legacy corpus files into the library';

    public function handle(LegacyImporter $importer): int
    {
        $database = $this->option('database-disk') !== null
            ? $this->stage((string) $this->option('database-disk'), (string) $this->option('database'))
            : $this->absolute((string) $this->option('database'));

        if (! is_file($database)) {
            $this->components->error("Legacy database not found at [{$database}].");

            return self::FAILURE;
        }

        if ($this->option('disk') !== null) {
            $disk = (string) $this->option('disk');
            $corpus = new DiskCorpus(Storage::disk($disk), $disk, (string) ($this->option('prefix') ?? ''));
        } else {
            $source = $this->absolute($this->option('source') !== null ? (string) $this->option('source') : 'storage/app/legacy/files');

            if (! is_dir($source)) {
                $this->components->error("Corpus root [{$source}] does not exist.");

                return self::FAILURE;
            }

            $corpus = new LocalCorpus($source);
        }

        $this->components->info("Reading the corpus from {$corpus->describe()}.");

        $importer->useLedger = true;
        $importer->dryRun = (bool) $this->option('dry-run');

        // Reversible on purpose. The reset is scoped to materials this import
        // created, so a re-import cannot reach anything authored in the app,
        // and the file ledger is left alone — the corpus is not re-uploaded,
        // only re-interpreted. That is what makes changing the mapping a
        // decision rather than a migration.
        if ($this->option('fresh') && ! $importer->dryRun) {
            $this->components->info(sprintf('Removed %d previously imported materials.', $importer->forget()));
        }

        $bar = $this->output->createProgressBar();
        $bar->setFormat(' %current% files  %elapsed:6s%  %message%');
        $bar->setMessage('');
        $importer->progress = function (string $path) use ($bar): void {
            $bar->setMessage(basename($path));
            $bar->advance();
        };

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $only = $this->option('only') !== null ? (string) $this->option('only') : null;

        $bar->start();
        $importer->run($database, $corpus, $limit, $only);
        $bar->finish();
        $this->newLine(2);

        $stats = $importer->stats;
        $this->components->twoColumnDetail($importer->dryRun ? 'Would ingest' : 'Ingested', (string) $stats['files_ingested']);
        $this->components->twoColumnDetail('Unchanged (skipped)', (string) $stats['files_unchanged']);
        $this->components->twoColumnDetail('Failed', (string) $stats['files_failed']);
        $this->components->twoColumnDetail('Attached to existing sets', (string) $stats['files_attached']);
        $this->components->twoColumnDetail('Representations created', (string) $stats['representations']);
        $this->components->twoColumnDetail('Not on disk', (string) $stats['files_missing']);
        $this->components->twoColumnDetail('Bytes', Number::fileSize($stats['bytes']));

        foreach ($importer->errors as $error) {
            $this->components->error($error);
        }

        return $stats['files_failed'] === 0 && $stats['errors'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Bring the legacy database down from a disk so SQLite can open it.
     *
     * It is around a gigabyte, so it streams to local storage and is reused on
     * a rerun when the size already matches.
     */
    private function stage(string $disk, string $path): string
    {
        $directory = storage_path('app/legacy-staged');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $local = $directory.'/'.substr(sha1($disk.'/'.$path), 0, 12).'.sqlite';
        $remote = Storage::disk($disk);

        if (! $remote->fileExists($path)) {
            $this->components->error("No database at [{$disk}://{$path}].");

            return $local;
        }

        $size = (int) $remote->fileSize($path);

        if (is_file($local) && filesize($local) === $size) {
            $this->components->info("Reusing the staged database at {$local}.");

            return $local;
        }

        $this->components->info(sprintf('Fetching %s://%s (%s).', $disk, $path, Number::fileSize($size, 1)));

        $stream = $remote->readStream($path);
        $handle = fopen($local, 'wb');

        if (! is_resource($stream) || $handle === false) {
            $this->components->error("Cannot stage [{$disk}://{$path}].");

            return $local;
        }

        try {
            stream_copy_to_stream($stream, $handle);
        } finally {
            fclose($handle);
            fclose($stream);
        }

        return $local;
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
