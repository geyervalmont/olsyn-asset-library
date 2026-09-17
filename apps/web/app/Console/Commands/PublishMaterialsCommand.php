<?php

namespace App\Console\Commands;

use App\Actions\Versions\CutVersion;
use App\Actions\Versions\PublishVersion;
use App\Enums\ReviewState;
use App\Models\Material;
use App\Models\Representation;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class PublishMaterialsCommand extends Command
{
    protected $signature = 'opal:versions:publish
        {--material= : Only this material code}
        {--all : Every material with approved canonical inputs, even if a version is already published}
        {--dry-run : List what would be published}';

    protected $description = 'Publish package-pinned material versions whose required consumer projections are ready';

    public function handle(CutVersion $cut, PublishVersion $publish): int
    {
        $query = Material::query()
            ->whereHas('variants.representations', fn (Builder $builder) => $builder
                ->where('review_state', ReviewState::Approved)
                ->whereHas('target', fn (Builder $target) => $target->where('is_canonical', true)))
            ->orderBy('code');

        if ($this->option('material') !== null) {
            $material = Material::resolveCode((string) $this->option('material'));
            if ($material === null) {
                $this->error('No material matches '.$this->option('material'));

                return self::FAILURE;
            }
            $query->whereKey($material->getKey());
        } elseif (! $this->option('all')) {
            $query->whereNull('current_version_id');
        }

        $published = 0;
        $skipped = 0;
        foreach ($query->cursor() as $material) {
            /** @var Material $material */
            $approved = Representation::query()
                ->approved()
                ->whereIn('variant_id', $material->variants()->select('id'))
                ->whereHas('target', fn (Builder $target) => $target->where('is_canonical', true))
                ->count();
            if ($this->option('dry-run')) {
                $packages = $material->variants()->withCount('packages')->get()->sum('packages_count');
                $this->line(sprintf('  %-40s %d approved authoring set(s), %d package(s)', $material->code, $approved, $packages));
                $published++;

                continue;
            }
            try {
                $version = $publish->handle($cut->handle($material, null, 'Published by opal:versions:publish'));
                $this->line(sprintf('  %-40s v%s', $material->code, $version->number ?? $version->getKey()));
                $published++;
            } catch (Throwable $error) {
                $this->warn(sprintf('  %-40s skipped: %s', $material->code, $error->getMessage()));
                $skipped++;
            }
        }

        $this->info(sprintf('%s %d material(s), %d skipped', $this->option('dry-run') ? 'Would publish' : 'Published', $published, $skipped));

        return self::SUCCESS;
    }
}
