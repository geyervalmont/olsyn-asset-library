<?php

namespace App\Console\Commands;

use App\Jobs\RenderPreview;
use App\Models\Material;
use App\Models\Variant;
use Illuminate\Console\Command;

class RenderPreviewsCommand extends Command
{
    protected $signature = 'opal:previews:render
        {--material= : Only this material, by code or alias}
        {--missing : Skip variants that already have a render for their current inputs}
        {--force : Render again even when an up-to-date render exists}
        {--sync : Run the renders now instead of queueing them}';

    protected $description = 'Queue lit-sphere preview renders for variants with an approved canonical set';

    public function handle(): int
    {
        $query = Variant::query()->with('material')->orderBy('id');

        if ($this->option('material') !== null) {
            $material = Material::resolveCode((string) $this->option('material'));

            if ($material === null) {
                $this->components->error(sprintf('No material matches [%s].', $this->option('material')));

                return self::FAILURE;
            }

            $query->where('material_id', $material->getKey());
        }

        $queued = 0;
        $skipped = 0;

        foreach ($query->lazy() as $variant) {
            $source = RenderPreview::sourceFor($variant);

            if ($source === null) {
                $skipped++;

                continue;
            }

            if ($this->option('missing') && ! $this->option('force') && RenderPreview::renderedFor($variant, RenderPreview::inputHash($source)) !== null) {
                $skipped++;

                continue;
            }

            $run = RenderPreview::forVariant($variant, null, (bool) $this->option('force'));

            if ($this->option('sync')) {
                (new RenderPreview((int) $run->getKey()))->handle();
            }

            $queued++;
            $this->line(sprintf('  %s %s', $this->option('sync') ? 'rendered' : 'queued  ', $variant->code));
        }

        $this->components->twoColumnDetail($this->option('sync') ? 'Rendered' : 'Queued', (string) $queued);
        $this->components->twoColumnDetail('Skipped', (string) $skipped);

        return self::SUCCESS;
    }
}
