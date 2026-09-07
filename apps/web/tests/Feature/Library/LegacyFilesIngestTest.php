<?php

use App\Library\FileStore;
use App\Library\Legacy\LegacyImporter;
use App\Models\File;
use App\Models\LegacyFileIngest;
use App\Models\Representation;
use App\Models\Variant;
use Database\Seeders\LibrarySeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(config('opal.files_disk'));
    $this->seed(LibrarySeeder::class);
    $this->dir = sys_get_temp_dir().'/opal-corpus-'.uniqid();
    mkdir($this->dir);
    legacyFixture($this->dir);
    $this->db = $this->dir.'/legacy.sqlite';
});

afterEach(function () {
    exec('rm -rf '.escapeshellarg($this->dir));
});

test('the corpus ingest is ledgered, resumable, and attaches late files to existing sets', function () {
    // The reference image is not in the corpus yet.
    $ref = $this->dir.'/files/Carpet/Tarkett/Academix/Enscape_Revit/ashen_ref.png';
    rename($ref, $ref.'.later');

    $this->artisan('opal:import:legacy-files', ['--database' => $this->db, '--source' => $this->dir.'/files', '--dry-run' => true])
        ->expectsOutputToContain('Would ingest')
        ->assertSuccessful();

    expect(File::query()->count())->toBe(0)
        ->and(LegacyFileIngest::query()->count())->toBe(0)
        ->and(Variant::query()->count())->toBeGreaterThan(0);

    $this->artisan('opal:import:legacy-files', ['--database' => $this->db, '--source' => $this->dir.'/files'])->assertSuccessful();

    $ledger = LegacyFileIngest::query()->orderBy('source_path')->get();
    expect($ledger)->toHaveCount(3)
        ->and($ledger->pluck('status')->unique()->all())->toBe([LegacyFileIngest::INGESTED])
        ->and($ledger->every(fn (LegacyFileIngest $row): bool => $row->file_id !== null && $row->sha256 !== null && $row->bytes > 0))->toBeTrue()
        ->and(File::query()->count())->toBe(3)
        ->and(Representation::query()->count())->toBe(2);

    // A rerun re-reads nothing: unchanged files come back from the ledger.
    $importer = app(LegacyImporter::class);
    $importer->useLedger = true;
    $seen = [];
    $importer->progress = function (string $path) use (&$seen): void {
        $seen[] = basename($path);
    };
    $importer->run($this->db, $this->dir.'/files');

    expect($importer->stats['files_unchanged'])->toBe(3)
        ->and($importer->stats['files_ingested'])->toBe(0)
        ->and(count($seen))->toBe(3)
        ->and(File::query()->count())->toBe(3)
        ->and(Representation::query()->count())->toBe(2);

    // The reference image arrives, and lands on the set that was created without it.
    rename($ref.'.later', $ref);
    $this->artisan('opal:import:legacy-files', ['--database' => $this->db, '--source' => $this->dir.'/files'])
        ->expectsOutputToContain('Attached to existing sets')
        ->assertSuccessful();

    $revit = Representation::query()->whereHas('target', fn ($query) => $query->where('slug', 'revit'))->sole();

    expect(LegacyFileIngest::query()->count())->toBe(4)
        ->and(File::query()->count())->toBe(4)
        ->and(Representation::query()->count())->toBe(2)
        ->and(array_keys($revit->filesByRole()))->toBe(['base_color', 'ref_image']);
});

test('a file that cannot be stored is recorded as failed and retried on the next run', function () {
    $broken = $this->dir.'/files/Carpet/Tarkett/Academix/SS/../Enscape_Revit/ashen_albedo.png';
    $broken = realpath($broken);

    $this->partialMock(FileStore::class, function ($mock) use ($broken): void {
        $mock->shouldReceive('store')->withArgs(fn ($input): bool => $input instanceof SplFileInfo && $input->getPathname() === $broken)->andThrow(new RuntimeException('the bucket is unreachable'));
        $mock->shouldReceive('store')->withAnyArgs()->passthru();
    });

    $this->artisan('opal:import:legacy-files', ['--database' => $this->db, '--source' => $this->dir.'/files'])->assertFailed();

    $row = LegacyFileIngest::query()->where('source_path', $broken)->sole();
    expect($row->status)->toBe(LegacyFileIngest::FAILED)
        ->and($row->error)->toBe('the bucket is unreachable')
        ->and($row->file_id)->toBeNull()
        ->and(LegacyFileIngest::query()->where('status', LegacyFileIngest::INGESTED)->count())->toBe(3);

    app()->forgetInstance(FileStore::class);
    Mockery::close();

    $this->artisan('opal:import:legacy-files', ['--database' => $this->db, '--source' => $this->dir.'/files'])->assertSuccessful();

    expect($row->refresh()->status)->toBe(LegacyFileIngest::INGESTED)
        ->and($row->file_id)->not->toBeNull()
        ->and($row->error)->toBeNull()
        ->and(LegacyFileIngest::query()->where('status', LegacyFileIngest::INGESTED)->count())->toBe(4);
});

test('the ingest reads a corpus staged on a disk', function () {
    Storage::fake('legacy');
    // The same tree the fixture wrote locally, staged on a bucket instead.
    foreach (['Enscape_Revit/ashen_albedo.png', 'Enscape_Revit/ashen_ref.png', 'SS/ashen_ss.png'] as $path) {
        $relative = 'Carpet/Tarkett/Academix/'.$path;
        Storage::disk('legacy')->put('corpus/'.$relative, (string) file_get_contents($this->dir.'/files/'.$relative));
    }

    $this->artisan('opal:import:legacy-files', ['--database' => $this->db, '--disk' => 'legacy', '--prefix' => 'corpus'])
        ->expectsOutputToContain('legacy://corpus')
        ->assertSuccessful();

    $ledger = LegacyFileIngest::query()->where('status', LegacyFileIngest::INGESTED)->orderBy('source_path')->get();

    expect($ledger)->not->toBeEmpty()
        // Keyed by disk, so a staged ingest and a local one never collide.
        ->and($ledger->first()->source_path)->toStartWith('legacy://corpus/')
        ->and($ledger->first()->file)->not->toBeNull()
        ->and(File::query()->count())->toBeGreaterThan(0);
});

test('the legacy database can be fetched from a disk as well as the corpus', function () {
    // Staged databases outlive a test run, so start from nothing.
    Illuminate\Support\Facades\File::deleteDirectory(storage_path('app/legacy-staged'));
    Storage::fake('legacy');
    Storage::disk('legacy')->put('material_assets.sqlite', (string) file_get_contents($this->db));
    foreach (['Enscape_Revit/ashen_albedo.png'] as $path) {
        $relative = 'Carpet/Tarkett/Academix/'.$path;
        Storage::disk('legacy')->put('corpus/'.$relative, (string) file_get_contents($this->dir.'/files/'.$relative));
    }

    $this->artisan('opal:import:legacy-files', [
        '--database' => 'material_assets.sqlite', '--database-disk' => 'legacy',
        '--disk' => 'legacy', '--prefix' => 'corpus',
    ])->expectsOutputToContain('Fetching legacy://material_assets.sqlite')->assertSuccessful();

    expect(LegacyFileIngest::query()->where('status', LegacyFileIngest::INGESTED)->count())->toBeGreaterThan(0);

    // A rerun reuses what was already fetched.
    $this->artisan('opal:import:legacy-files', [
        '--database' => 'material_assets.sqlite', '--database-disk' => 'legacy',
        '--disk' => 'legacy', '--prefix' => 'corpus',
    ])->expectsOutputToContain('Reusing the staged database')->assertSuccessful();
});
