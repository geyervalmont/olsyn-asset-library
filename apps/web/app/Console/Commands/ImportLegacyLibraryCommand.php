<?php

namespace App\Console\Commands;

use App\Library\Legacy\LegacyImporter;
use Illuminate\Console\Command;

class ImportLegacyLibraryCommand extends Command
{
    protected $signature = 'opal:import:legacy
        {--database=storage/app/legacy/material_assets.sqlite : Path to the legacy SQLite database}
        {--files=storage/app/legacy/files : Root of the legacy folder tree, or "none" to import metadata only}
        {--limit= : Import at most this many products}
        {--only= : Import a single product by legacy slug}
        {--fresh : Remove everything a previous import created before importing}';

    protected $description = 'Import products, variants and available files from the Material Asset Library handoff';

    public function handle(LegacyImporter $importer): int
    {
        $database = base_path((string) $this->option('database'));
        $files = (string) $this->option('files');
        $filesRoot = $files === 'none' ? null : base_path($files);

        if (! is_file($database)) {
            $this->components->error("Legacy database not found at [{$database}].");

            return self::FAILURE;
        }

        if ($filesRoot !== null && ! is_dir($filesRoot)) {
            $this->components->warn("Files root [{$filesRoot}] does not exist; importing metadata only.");
            $filesRoot = null;
        }

        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $only = $this->option('only') !== null ? (string) $this->option('only') : null;

        if ($this->option('fresh')) {
            $this->components->info(sprintf('Removed %d previously imported materials.', $importer->forget()));
        }

        $importer->run($database, $filesRoot, $limit, $only, fn (string $line) => $this->line($line));

        $this->newLine();
        $this->components->twoColumnDetail('Products seen', (string) $importer->stats['products']);
        $this->components->twoColumnDetail('Materials created', (string) $importer->stats['materials_created']);
        $this->components->twoColumnDetail('Materials already present', (string) $importer->stats['materials_existing']);
        $this->components->twoColumnDetail('Variants created', (string) $importer->stats['variants']);
        $this->components->twoColumnDetail('Variants merged', (string) $importer->stats['variants_merged']);
        $this->components->twoColumnDetail('Files attached', (string) $importer->stats['files']);
        $this->components->twoColumnDetail('Representations created', (string) $importer->stats['representations']);
        $this->components->twoColumnDetail('Files not on disk', (string) $importer->stats['files_missing']);
        $this->components->twoColumnDetail('Files skipped (role/variant)', (string) $importer->stats['files_skipped']);
        $this->components->twoColumnDetail('Errors', (string) $importer->stats['errors']);

        foreach ($importer->errors as $error) {
            $this->components->error($error);
        }

        return $importer->stats['errors'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
