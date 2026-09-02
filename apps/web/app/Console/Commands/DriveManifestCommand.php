<?php

namespace App\Console\Commands;

use App\Library\Drives\DriveNamespace;
use App\Models\Drive;
use Illuminate\Console\Command;

class DriveManifestCommand extends Command
{
    protected $signature = 'opal:drive:manifest
        {drive : The drive slug}
        {--out= : Write the manifest to this path instead of printing it}';

    protected $description = 'Render a PrismFS namespace manifest for a drive';

    public function handle(DriveNamespace $namespace): int
    {
        $drive = Drive::query()->where('slug', $this->argument('drive'))->first();

        if ($drive === null) {
            $this->components->error(sprintf('No drive found for [%s].', $this->argument('drive')));

            return self::FAILURE;
        }

        $yaml = $namespace->toYaml($drive);
        $out = $this->option('out');

        if (is_string($out) && $out !== '') {
            file_put_contents($out, $yaml);
            $this->components->info(sprintf('Wrote %d entries for drive [%s] to %s.', count($namespace->entries($drive)), $drive->slug, $out));

            return self::SUCCESS;
        }

        $this->output->write($yaml);

        return self::SUCCESS;
    }
}
