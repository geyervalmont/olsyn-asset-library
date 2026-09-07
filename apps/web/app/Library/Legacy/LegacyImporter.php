<?php

namespace App\Library\Legacy;

use App\Actions\Materials\AddVariant;
use App\Actions\Provenance\RecordProvenance;
use App\Actions\Representations\CreateRepresentation;
use App\Enums\MaterialStatus;
use App\Enums\ReviewState;
use App\Library\FileStore;
use App\Models\Alias;
use App\Models\Category;
use App\Models\File;
use App\Models\LegacyFileIngest;
use App\Models\MapRole;
use App\Models\Material;
use App\Models\ProvenanceEvent;
use App\Models\Representation;
use App\Models\Source;
use App\Models\Supplier;
use App\Models\Variant;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SplFileInfo;
use Throwable;

/**
 * Imports Matt's Material Asset Library (SQLite + folder tree) into OPAL.
 *
 * Every product becomes a material and every colourway a variant, with the
 * legacy canonical key kept as an alias. Files are attached only where the
 * bytes exist under the files root; everything else is metadata with an
 * "imported" provenance event pointing at the legacy path.
 */
class LegacyImporter
{
    public const SOURCE_SLUG = 'legacy-material-asset-library';

    /** Legacy category names that do not map by name alone. */
    private const CATEGORY_MAP = [
        'anodised_eloctrospray' => 'ANO',
        'feature_paint_plaster' => 'PLS',
        'pending_classification' => 'UNC',
        '_archived_historical' => 'UNC',
    ];

    /** Legacy channel → target slug; unlisted channels are skipped. */
    private const CHANNEL_TARGETS = [
        'Enscape_Revit' => 'revit',
        'Omni_PBR' => 'pbr',
        'AI_Mat' => 'pbr',
    ];

    /** Legacy asset role → map role slug; unlisted roles are skipped. */
    private const ROLE_MAP = [
        'albedo' => 'base_color',
        'base_color' => 'base_color',
        'albedo_compressed' => 'base_color',
        'base_color_compressed_lossless' => 'base_color',
        'normal' => 'normal',
        'normal_gl' => 'normal',
        'normal_dx' => 'normal',
        'normal_gl_compressed_lossless' => 'normal',
        'roughness' => 'roughness',
        'roughness_compressed_lossless' => 'roughness',
        'metallic' => 'metallic',
        'height' => 'height',
        'displacement' => 'height',
        'bump' => 'bump',
        'ao' => 'ao',
        'ao_compressed_lossless' => 'ao',
        'opacity' => 'opacity',
        'alpha' => 'opacity',
        'specular' => 'specular',
        'transmission' => 'transmission',
        'ref_image' => 'ref_image',
        'source_image' => 'ref_image',
        'render' => 'render',
    ];

    private const APPROVED_STATES = ['approved', 'active'];

    private const SKIPPED_STATES = ['archived', 'archived_superseded', 'missing', 'superseded'];

    /** @var array<string, int> */
    public array $stats = [
        'products' => 0, 'materials_created' => 0, 'materials_existing' => 0, 'variants' => 0,
        'variants_merged' => 0, 'files' => 0, 'representations' => 0, 'files_missing' => 0, 'files_skipped' => 0, 'errors' => 0,
        'files_ingested' => 0, 'files_unchanged' => 0, 'files_failed' => 0, 'files_attached' => 0, 'bytes' => 0,
    ];

    /** @var list<string> */
    public array $errors = [];

    private Connection $legacy;

    private ?Source $librarySource = null;

    /** @var array<string, Source> */
    private array $supplierSources = [];

    /** Record every corpus file in the ledger and skip the ones already ingested unchanged. */
    public bool $useLedger = false;

    /** Walk and hash nothing; count what would be stored. */
    public bool $dryRun = false;

    /** Called once per corpus file considered, for progress bars. */
    public ?Closure $progress = null;

    public function __construct(
        private readonly AddVariant $addVariant,
        private readonly CreateRepresentation $createRepresentation,
        private readonly RecordProvenance $provenance,
        private readonly FileStore $files,
    ) {}

    /**
     * @param  Closure(string): void|null  $log
     */
    /**
     * Remove everything a previous import created, so a corrected import can
     * replace it. Files are content-addressed and stay.
     */
    public function forget(): int
    {
        $materials = Material::query()->whereNotNull('specifications->legacy->product_id')->get();

        foreach ($materials as $material) {
            $variantIds = $material->variants()->pluck('id');
            $representationIds = Representation::query()->whereIn('variant_id', $variantIds)->pluck('id');

            ProvenanceEvent::query()
                ->where(fn ($q) => $q->where('subject_type', 'material')->where('subject_id', $material->getKey()))
                ->orWhere(fn ($q) => $q->where('subject_type', 'variant')->whereIn('subject_id', $variantIds))
                ->orWhere(fn ($q) => $q->where('subject_type', 'representation')->whereIn('subject_id', $representationIds))
                ->delete();

            Alias::query()
                ->where(fn ($q) => $q->where('aliasable_type', 'material')->where('aliasable_id', $material->getKey()))
                ->orWhere(fn ($q) => $q->where('aliasable_type', 'variant')->whereIn('aliasable_id', $variantIds))
                ->delete();

            $material->delete();
        }

        return $materials->count();
    }

    public function run(string $databasePath, CorpusSource|string|null $filesRoot = null, ?int $limit = null, ?string $onlySlug = null, ?Closure $log = null): void
    {
        $corpus = is_string($filesRoot) ? new LocalCorpus(rtrim($filesRoot, '/')) : $filesRoot;

        $this->legacy = $this->connect($databasePath);
        $log ??= fn (string $line): null => null;

        $products = $this->legacy->table('product as p')
            ->leftJoin('supplier as s', 's.id', '=', 'p.supplier_id')
            ->leftJoin('category as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('product_generation_context as g', 'g.product_id', '=', 'p.id')
            ->select('p.*', 's.name as supplier_name', 's.slug as supplier_slug', 's.website_url as supplier_website', 'c.name as category_name',
                'g.material_type', 'g.material_form', 'g.module_width_mm', 'g.module_height_mm', 'g.module_thickness_mm', 'g.joint_spacing_mm',
                'g.repeat_width_mm', 'g.repeat_height_mm', 'g.install_pattern', 'g.finish_notes', 'g.surface_notes', 'g.scale_text', 'g.module_text', 'g.product_description')
            ->when($onlySlug !== null, fn ($query) => $query->where('p.slug', $onlySlug))
            ->orderBy('c.name')->orderBy('s.name')->orderBy('p.name')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get();

        foreach ($products as $product) {
            $this->stats['products']++;

            try {
                Material::withDeferredSearchRefresh(fn () => DB::transaction(function () use ($product, $corpus, $log): void {
                    $material = $this->importProduct($product);
                    $variants = $this->importVariants($material, $product);

                    if ($corpus !== null) {
                        $this->importFiles($material, $variants, $product, $corpus);
                    }

                    $material->refreshSearchText();
                    $log(sprintf('%s  %s (%d variants)', $material->code, $material->name, count($variants)));
                }));
            } catch (Throwable $exception) {
                $this->stats['errors']++;
                $this->errors[] = sprintf('%s: %s', $product->name, $exception->getMessage());
                $log(sprintf('ERROR %s: %s', $product->name, $exception->getMessage()));
            }
        }
    }

    private function connect(string $path): Connection
    {
        config(['database.connections.legacy_import' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => false]]);

        return DB::connection('legacy_import');
    }

    private function importProduct(\stdClass $product): Material
    {
        $existing = Material::query()->where('specifications->legacy->product_id', $product->id)->first();

        if ($existing !== null) {
            $this->stats['materials_existing']++;

            return $existing;
        }

        $category = $this->category((string) $product->category_name);
        $supplier = $this->supplier($product);
        $archived = str_starts_with((string) $product->category_name, '_') || $product->product_status === 'archived';

        $material = Material::create([
            'name' => trim((string) $product->name) ?: 'Untitled',
            'category_id' => $category->getKey(),
            'supplier_id' => $supplier?->getKey(),
            'source_id' => $supplier === null ? $this->librarySource()->getKey() : $this->supplierSource($supplier, (string) $product->supplier_website)->getKey(),
            'collection' => $this->clean($product->collection_name),
            'supplier_product_code' => $this->clean($product->supplier_product_code),
            'description' => $this->clean($product->description) ?? $this->clean($product->product_description),
            'material_type' => $this->clean($product->material_type),
            'form' => $this->clean($product->material_form),
            'tile_width_mm' => $this->number($product->module_width_mm) ?? $this->number($product->repeat_width_mm),
            'tile_height_mm' => $this->number($product->module_height_mm) ?? $this->number($product->repeat_height_mm),
            'thickness_mm' => $this->number($product->module_thickness_mm),
            'install_pattern' => $this->clean($product->install_pattern),
            'sqm_cost' => $this->number($product->sqm_cost),
            'currency' => $this->number($product->sqm_cost) !== null ? 'AUD' : null,
            'lead_time' => $this->clean($product->lead_time),
            'status' => $archived ? MaterialStatus::Archived : MaterialStatus::Active,
            'specifications' => array_filter([
                'joint_spacing_mm' => $this->number($product->joint_spacing_mm),
                'module_text' => $this->clean($product->module_text),
                'scale_text' => $this->clean($product->scale_text),
                'finish_notes' => $this->clean($product->finish_notes),
                'surface_notes' => $this->clean($product->surface_notes),
                'legacy' => [
                    'product_id' => $product->id,
                    'slug' => $product->slug,
                    'category' => $product->category_name,
                    'path' => $product->current_product_path,
                    'source_url' => $this->clean($product->source_url),
                ],
            ], fn (mixed $value): bool => $value !== null),
        ]);

        $material->syncTagNames(array_values($this->tags($product)));

        $this->provenance->handle(
            $material,
            'imported',
            null,
            source: $this->librarySource(),
            sourceUrl: $this->clean($product->source_url),
            externalRef: (string) $product->current_product_path,
            parameters: ['legacy_product_id' => $product->id, 'legacy_slug' => $product->slug],
            notes: 'Imported from the Material Asset Library handoff.',
        );

        $this->stats['materials_created']++;

        return $material;
    }

    /**
     * @return array<string, Variant> keyed by legacy variant id
     */
    private function importVariants(Material $material, \stdClass $product): array
    {
        $rows = $this->legacy->table('material_variant as v')
            ->leftJoin('material_variant_metadata as m', 'm.material_variant_id', '=', 'v.id')
            ->where('v.product_id', $product->id)
            ->select('v.*', 'm.colour_family', 'm.dominant_hex', 'm.colour_description', 'm.info_tags')
            ->orderBy('v.colourway_name')
            ->get();

        $variants = [];

        if ($rows->isEmpty() && $material->variants()->count() === 0) {
            $variants['default'] = $this->addVariant->handle($material, [], 'Default');
            $this->stats['variants']++;

            return $variants;
        }

        /** @var array<string, Variant> $byName */
        $byName = [];

        foreach ($rows as $row) {
            $key = strtoupper(trim((string) $row->canonical_key));
            $existing = $key === '' ? null : Variant::resolveCode($key);

            if ($existing !== null && (string) $existing->material_id === (string) $material->getKey()) {
                $variants[$row->id] = $existing;
                $byName[strtolower($existing->name)] = $existing;

                continue;
            }

            $supplierCode = $this->clean($row->supplier_colour_code);
            $supplierCode = in_array(strtolower((string) $supplierCode), ['', 'na'], true) ? null : $supplierCode;
            $name = LegacyNames::colourway($row->colourway_name, $material->name, $supplierCode, $product->supplier_name)
                ?? LegacyNames::colourway($row->supplier_colour_name, $material->name, $supplierCode, $product->supplier_name)
                ?? 'Default';

            // Several legacy rows (one per texture map, or per pattern alias) can be one colourway.
            if (isset($byName[strtolower($name)])) {
                $variant = $byName[strtolower($name)];

                if ($key !== '' && strlen($key) <= 191 && Alias::query()->where('code', $key)->doesntExist()) {
                    $variant->addAlias($key, 'legacy canonical_key (merged)');
                }

                $variants[$row->id] = $variant;
                $this->stats['variants_merged']++;

                continue;
            }

            $pattern = $this->clean($row->install_variant);

            if ($pattern !== null && (str_ends_with(strtolower($pattern), '.pdf') || strtolower($pattern) === 'material download')) {
                $pattern = null;
            }

            $attributes = array_filter([
                'colourway' => ['value' => $name, 'supplier_code' => $supplierCode, 'supplier_name' => $this->clean($row->supplier_colour_name)],
                'finish' => $this->clean($row->finish_name),
                'pattern' => $pattern,
            ]);

            $overrides = array_filter([
                'tile_width_mm' => $this->override($this->number($row->scale_width_mm), $material->tile_width_mm),
                'tile_height_mm' => $this->override($this->number($row->scale_height_mm), $material->tile_height_mm),
                'repeat_type' => $this->repeatType($row->repeat_type),
                'dominant_hex' => $this->hex($row->dominant_hex),
                'colour_family' => $this->clean($row->colour_family),
            ], fn (mixed $value): bool => $value !== null);

            $variant = $this->addVariant->handle($material, $attributes, $name, $overrides);

            if ($key !== '' && strlen($key) <= 191) {
                $variant->addAlias($key, 'legacy canonical_key');
            }

            $variants[$row->id] = $variant;
            $byName[strtolower($name)] = $variant;
            $this->stats['variants']++;
        }

        return $variants;
    }

    /**
     * @param  array<string, Variant>  $variants
     */
    private function importFiles(Material $material, array $variants, \stdClass $product, CorpusSource $corpus): void
    {
        $rows = $this->legacy->table('asset_file')
            ->where('product_id', $product->id)
            ->whereIn('channel', array_keys(self::CHANNEL_TARGETS))
            ->whereNotIn('asset_state', self::SKIPPED_STATES)
            ->orderBy('relative_path')
            ->get();

        /** @var array<string, array{variant: Variant, channel: string, files: array<string, File>, states: list<string>, ids: list<mixed>, paths: list<string>}> $groups */
        $groups = [];
        // Every row the legacy library holds for this material that could
        // become a file here, so the gap between held and staged is visible.
        $expected = 0;

        foreach ($rows as $row) {
            $variant = $variants[$row->material_variant_id] ?? null;
            $role = self::ROLE_MAP[(string) $row->asset_role] ?? null;

            if ($variant === null || $role === null) {
                $this->stats['files_skipped']++;

                continue;
            }

            $expected++;

            $relative = (string) $row->relative_path;

            if (! $corpus->has($relative)) {
                $this->stats['files_missing']++;

                continue;
            }

            $groupKey = $variant->getKey().'|'.$row->channel.'|'.dirname((string) $row->relative_path);
            $groups[$groupKey] ??= ['variant' => $variant, 'channel' => (string) $row->channel, 'files' => [], 'states' => [], 'ids' => [], 'paths' => []];

            if (isset($groups[$groupKey]['files'][$role])) {
                $this->stats['files_skipped']++;

                continue;
            }

            $file = $this->storeCorpusFile($corpus, $relative);

            if ($file === null) {
                continue;
            }

            $groups[$groupKey]['files'][$role] = $file;
            $groups[$groupKey]['states'][] = (string) $row->asset_state;
            $groups[$groupKey]['ids'][] = $row->id;
            $groups[$groupKey]['paths'][] = $relative;
            $this->stats['files']++;
        }

        if ($material->legacy_files_expected !== $expected) {
            $material->forceFill(['legacy_files_expected' => $expected])->save();
        }

        foreach ($groups as $group) {
            if ($group['files'] === []) {
                continue;
            }

            $directory = dirname($group['paths'][0]);
            $variant = $group['variant'];

            $existing = $variant->representations()->where('metadata->legacy->directory', $directory)->first();

            if ($existing !== null) {
                $this->attachMissing($existing, $group['files']);

                continue;
            }

            $pixels = collect($group['files'])->map(fn ($file): int => max((int) $file->width_px, (int) $file->height_px))->max() ?: 1024;
            $approved = array_diff($group['states'], self::APPROVED_STATES) === [];

            $representation = $this->createRepresentation->handle(
                $variant,
                self::CHANNEL_TARGETS[$group['channel']],
                $pixels,
                $group['files'],
                metadata: ['legacy' => ['channel' => $group['channel'], 'directory' => $directory, 'asset_ids' => $group['ids'], 'states' => $group['states']]],
            );

            if ($approved) {
                $representation->forceFill(['review_state' => ReviewState::Approved, 'reviewed_at' => now()])->save();
            }

            $derivations = $this->legacy->table('asset_derivation')
                ->whereIn('output_asset_file_id', $group['ids'])
                ->get(['derivation_type', 'tool_name', 'tool_version'])
                ->map(fn (object $row): array => ['type' => $row->derivation_type, 'tool' => $row->tool_name, 'version' => $row->tool_version])
                ->unique()->values()->all();

            $sources = $this->legacy->table('asset_source')
                ->whereIn('asset_file_id', $group['ids'])
                ->get(['source_type', 'source_url', 'source_page_url', 'license_notes'])
                ->map(fn (object $row): array => array_filter((array) $row))
                ->values()->all();

            $isGenerated = $group['channel'] === 'AI_Mat' && $derivations !== [];

            $this->provenance->handle(
                $representation,
                'imported',
                null,
                outputs: array_values(collect($group['files'])->map(fn ($file, $role) => [$file, $role])->all()),
                tool: $isGenerated ? ($derivations[0]['tool'] ?? null) : null,
                toolVersion: $isGenerated ? ($derivations[0]['version'] ?? null) : null,
                source: $isGenerated || $material->supplier_id === null ? $this->librarySource() : $this->supplierSource($material->supplier, (string) $product->supplier_website),
                sourceUrl: $sources[0]['source_url'] ?? null,
                externalRef: $directory,
                parameters: array_filter(['channel' => $group['channel'], 'legacy_states' => $group['states'], 'derivations' => $derivations, 'sources' => $sources]),
                notes: 'Files copied from the Material Asset Library handoff.',
            );

            $this->stats['representations']++;
        }
    }

    /**
     * Store one corpus file, through the ledger when enabled: unchanged files
     * are not re-read, failures are recorded and skipped, dry runs only count.
     */
    private function storeCorpusFile(CorpusSource $corpus, string $relative): ?File
    {
        $key = $corpus->key($relative);

        if ($this->progress !== null) {
            ($this->progress)($key);
        }

        if (! $this->useLedger) {
            return $corpus->withLocalFile($relative, fn (string $path): File => $this->files->store(new SplFileInfo($path), basename($relative)));
        }

        $bytes = $corpus->size($relative);
        $mtime = $corpus->mtime($relative);
        $ledger = LegacyFileIngest::query()->where('source_path', $key)->first();

        if ($ledger !== null && $ledger->matches($bytes, $mtime)) {
            $this->stats['files_unchanged']++;

            return $ledger->file;
        }

        if ($this->dryRun) {
            $this->stats['files_ingested']++;
            $this->stats['bytes'] += $bytes;

            return null;
        }

        try {
            $file = $corpus->withLocalFile($relative, fn (string $path): File => $this->files->store(new SplFileInfo($path), basename($relative)));
        } catch (Throwable $exception) {
            LegacyFileIngest::query()->updateOrCreate(['source_path' => $key], [
                'bytes' => $bytes, 'mtime' => Carbon::createFromTimestamp($mtime), 'status' => LegacyFileIngest::FAILED,
                'error' => Str::limit($exception->getMessage(), 1000), 'processed_at' => now(),
            ]);
            $this->stats['files_failed']++;
            $this->errors[] = sprintf('%s: %s', $key, $exception->getMessage());

            return null;
        }

        LegacyFileIngest::query()->updateOrCreate(['source_path' => $key], [
            'bytes' => $bytes, 'mtime' => Carbon::createFromTimestamp($mtime), 'sha256' => $file->sha256, 'file_id' => $file->getKey(),
            'status' => LegacyFileIngest::INGESTED, 'error' => null, 'processed_at' => now(),
        ]);
        $this->stats['files_ingested']++;
        $this->stats['bytes'] += $bytes;

        return $file;
    }

    /**
     * A representation imported before the corpus was complete gains the
     * roles that have since arrived.
     *
     * @param  array<string, File>  $files
     */
    private function attachMissing(Representation $representation, array $files): void
    {
        if ($this->dryRun) {
            return;
        }

        $present = $representation->representationFiles()->with('role')->get()->map(fn ($representationFile): string => $representationFile->role->slug)->all();

        foreach ($files as $role => $file) {
            if (in_array($role, $present, true)) {
                continue;
            }

            $mapRole = MapRole::fromSlug($role);
            $representation->representationFiles()->create([
                'file_id' => $file->getKey(),
                'map_role_id' => $mapRole->getKey(),
                'colour_space' => $file->colour_space ?? $mapRole->colour_space,
            ]);
            $this->stats['files_attached']++;
        }
    }

    private function category(string $legacyName): Category
    {
        $normalised = strtolower(trim($legacyName));
        $code = self::CATEGORY_MAP[$normalised] ?? null;

        if ($code === null) {
            $byName = Category::query()
                ->where('kind', Category::KIND_MATERIAL)
                ->whereRaw('lower(name) = ?', [str_replace('_', ' ', $normalised)])
                ->first();

            if ($byName !== null) {
                return $byName;
            }
        }

        if ($code === 'UNC' || $code === null) {
            return Category::query()->firstOrCreate(
                ['kind' => Category::KIND_MATERIAL, 'code' => 'UNC'],
                ['name' => 'Unclassified', 'sort_order' => 900],
            );
        }

        return Category::query()->where('kind', Category::KIND_MATERIAL)->where('code', $code)->firstOrFail();
    }

    private function supplier(\stdClass $product): ?Supplier
    {
        $name = $this->clean($product->supplier_name);

        if ($name === null || in_array(strtolower($name), ['opal', 'unknown', 'in-house'], true)) {
            return null;
        }

        return Supplier::query()->firstOrCreate(
            ['slug' => Str::slug((string) ($product->supplier_slug ?: $name))],
            ['name' => $name, 'website' => $this->clean($product->supplier_website)],
        );
    }

    private function supplierSource(Supplier $supplier, string $website): Source
    {
        return $this->supplierSources[$supplier->slug] ??= Source::query()->firstOrCreate(
            ['slug' => $supplier->slug.'-website'],
            ['name' => $supplier->name.' website', 'kind' => 'supplier', 'supplier_id' => $supplier->getKey(), 'url' => $this->clean($website)],
        );
    }

    private function librarySource(): Source
    {
        return $this->librarySource ??= Source::query()->firstOrCreate(
            ['slug' => self::SOURCE_SLUG],
            ['name' => 'Legacy Material Asset Library', 'kind' => 'in_house', 'notes' => 'Matt\'s Material Asset Library handoff (2026-08-26). Files were produced in-house or downloaded from suppliers; check the event for the original source.'],
        );
    }

    /**
     * @return array<int, string>
     */
    private function tags(\stdClass $product): array
    {
        $noise = array_map('strtolower', array_filter([
            $product->name, $product->supplier_name, $product->category_name, str_replace('_', ' ', (string) $product->category_name),
            ...preg_split('/\s+/', (string) $product->name) ?: [],
        ]));

        return collect(explode(',', (string) $product->search_tags))
            ->map(fn (string $tag): string => trim($tag))
            ->filter(fn (string $tag): bool => $tag !== '' && strlen($tag) > 3 && ! in_array(strtolower($tag), $noise, true))
            ->unique(fn (string $tag): string => strtolower($tag))
            ->values()
            ->map(fn (string $tag): string => $tag)
            ->all();
    }

    private function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    private function override(?float $value, ?string $default): ?float
    {
        if ($value === null) {
            return null;
        }

        return $default !== null && abs((float) $default - $value) < 0.01 ? null : $value;
    }

    private function repeatType(mixed $value): ?string
    {
        return match ((string) $value) {
            'tile' => 'tile',
            'approximate_pattern_repeat' => 'pattern',
            'approximate_surface_crop', 'operator_confirmed_surface_crop' => 'surface_crop',
            default => null,
        };
    }

    private function hex(mixed $value): ?string
    {
        $value = $this->clean($value);

        return $value !== null && preg_match('/^#?[0-9a-fA-F]{6}$/', $value) === 1 ? '#'.strtolower(ltrim($value, '#')) : null;
    }
}
