<?php

namespace App\Console\Commands;

use App\Library\Legacy\LegacyImporter;
use Illuminate\Console\Command;
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
        {--database=storage/app/legacy/material_assets.sqlite : Path to the legacy SQLite database}
        {--limit= : Consider at most this many products}
        {--only= : A single product by legacy slug}
        {--dry-run : Report what would be stored without reading file contents}';

    protected $description = 'Resumable ingest of the legacy corpus files into the library';

    public function handle(LegacyImporter $importer): int
    {
        $database = $this->absolute((string) $this->option('database'));
        $source = $this->absolute($this->option('source') !== null ? (string) $this->option('source') : 'storage/app/legacy/files');

        if (! is_file($database)) {
            $this->components->error("Legacy database not found at [{$database}].");

            return self::FAILURE;
        }

        if (! is_dir($source)) {
            $this->components->error("Corpus root [{$source}] does not exist.");

            return self::FAILURE;
        }

        $importer->useLedger = true;
        $importer->dryRun = (bool) $this->option('dry-run');

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
        $importer->run($database, $source, $limit, $only);
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

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
