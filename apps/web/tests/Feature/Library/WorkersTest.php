<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Actions\Materials\AddVariant;
use App\Actions\Representations\CreateRepresentation;
use App\Actions\Representations\ReviewRepresentation;
use App\Enums\ReviewState;
use App\Enums\Role;
use App\Enums\RunStatus;
use App\Jobs\DownscaleRepresentation;
use App\Jobs\TrackedJob;
use App\Library\FileStore;
use App\Models\FileAccess;
use App\Models\Material;
use App\Models\QualityTier;
use App\Models\Representation;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkerRun;
use Database\Seeders\LibrarySeeder;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Multitenancy\Jobs\NotTenantAware;

class SucceedingJob extends TrackedJob
{
    public static function type(): string
    {
        return 'succeeding';
    }

    protected function execute(WorkerRun $run): ?array
    {
        return ['echo' => $run->payload['word'] ?? null];
    }
}

class FailingJob extends TrackedJob
{
    public int $tries = 1;

    public static function type(): string
    {
        return 'failing';
    }

    protected function execute(WorkerRun $run): ?array
    {
        throw new RuntimeException('the disk is full');
    }
}

function texturePng(int $size, int $grey): string
{
    $image = imagecreatetruecolor($size, $size);
    imagefilledrectangle($image, 0, 0, $size, $size, imagecolorallocate($image, $grey, $grey, $grey));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

beforeEach(function () {
    Storage::fake(config('opal.files_disk'));
    $this->seed(LibrarySeeder::class);
    app(SyncRolesAndPermissions::class)->handle();
    $this->tenant = Tenant::factory()->create();
    $this->editor = User::factory()->withTenant($this->tenant, Role::Editor)->create();
    $this->tenant->makeCurrent();
});

afterEach(fn () => Tenant::forgetCurrent());

test('a tracked job records its run, result, actor and tenant', function () {
    $material = Material::factory()->create();

    $run = SucceedingJob::launch(['word' => 'hello'], $material, $this->editor);

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->result)->toBe(['echo' => 'hello'])
        ->and($run->attempts)->toBe(1)
        ->and($run->duration_ms)->not->toBeNull()
        ->and($run->subject?->is($material))->toBeTrue()
        ->and($run->actor?->is($this->editor))->toBeTrue()
        ->and($run->tenant_id)->toBe($this->tenant->getKey())
        ->and($run->uuid)->toHaveLength(36);
});

test('tracked jobs run outside tenant context and remember the requesting tenant', function () {
    expect(new SucceedingJob(1))->toBeInstanceOf(NotTenantAware::class);

    Tenant::forgetCurrent();
    $run = SucceedingJob::launch(['word' => 'no-tenant']);

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->tenant_id)->toBeNull();
});

test('a failing job records the error and can be queued again', function () {
    expect(fn () => FailingJob::launch(['x' => 1]))->toThrow(RuntimeException::class);

    $run = WorkerRun::query()->where('type', 'failing')->sole();

    expect($run->status)->toBe(RunStatus::Failed)
        ->and($run->error)->toBe('the disk is full')
        ->and($run->finished_at)->not->toBeNull();

    expect(fn () => FailingJob::requeue($run, $this->editor))->toThrow(RuntimeException::class);

    expect(WorkerRun::query()->where('type', 'failing')->count())->toBe(2)
        ->and(WorkerRun::query()->where('type', 'failing')->latest('id')->first()?->payload)->toBe(['x' => 1]);
});

test('downscaling produces smaller approved tiers with provenance, and skips what exists', function () {
    $material = Material::factory()->create();
    $variant = app(AddVariant::class)->handle($material, ['colourway' => 'Ashen']);
    $store = app(FileStore::class);
    $source = app(ReviewRepresentation::class)->handle(app(CreateRepresentation::class)->handle($variant, 'pbr', 512, [
        'base_color' => $store->store(texturePng(512, 120), 'base.png'),
        'roughness' => $store->store(texturePng(512, 200), 'rough.png'),
    ]), ReviewState::Approved);
    QualityTier::forPixels(128);
    QualityTier::forPixels(256);

    $run = DownscaleRepresentation::forRepresentation($source, ['256px', '128px', '8k'], $this->editor);

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and(collect($run->result['created'])->pluck('tier')->all())->toBe(['256px', '128px'])
        ->and($run->result['skipped'][0]['tier'])->toBe('8k');

    $small = Representation::query()->forKey($variant, $source->target, QualityTier::fromSlug('128px'))->approved()->sole();
    $base = $small->fileFor('base_color');

    expect($small->kind)->toBe($source->kind)
        ->and($base?->width_px)->toBe(128)
        ->and($base?->height_px)->toBe(128)
        ->and($base?->mime_type)->toBe('image/png')
        ->and($base?->original_name)->toBe($variant->code.'_base_color_128px.png')
        ->and(array_keys($small->filesByRole()))->toBe(['base_color', 'roughness'])
        ->and($small->metadata['worker_run'] ?? null)->toBe($run->uuid);

    $event = $small->provenanceEvents()->sole();

    expect($event->action)->toBe('downscaled')
        ->and($event->job_id)->toBe($run->uuid)
        ->and($event->inputs()->count())->toBe(2)
        ->and($event->outputs()->count())->toBe(2)
        ->and($base?->lineage()->first()?->is($event))->toBeTrue();

    $again = DownscaleRepresentation::forRepresentation($source, ['128px']);

    expect($again->result['created'])->toBe([])
        ->and($again->result['skipped'][0]['reason'])->toBe('approved tier already exists');

    $this->artisan('opal:tiers:backfill', ['--tiers' => '256px,128px,64px'])->assertSuccessful();
});

test('the jobs page lists runs, filters by status and retries failures', function () {
    SucceedingJob::launch(['word' => 'ok'], null, $this->editor);
    try {
        FailingJob::launch();
    } catch (RuntimeException) {
    }

    $this->actingAs($this->editor)->get(route('jobs.index'))->assertOk()->assertSee('data-test="run-row"', false);

    $this->tenant->makeCurrent();
    config(['opal.workers.failing' => FailingJob::class]);

    Livewire::actingAs($this->editor)
        ->test('pages::jobs.index')
        ->assertSee('the disk is full')
        ->set('status', 'succeeded')
        ->assertDontSee('the disk is full')
        ->set('status', 'failed')
        ->assertSee('data-test="retry"', false);

    $failed = WorkerRun::query()->where('type', 'failing')->sole();

    try {
        Livewire::actingAs($this->editor)->test('pages::jobs.index')->call('retry', $failed->getKey());
    } catch (RuntimeException) {
    }

    expect(WorkerRun::query()->where('type', 'failing')->count())->toBe(2);
});

test('every web read of a library file is recorded', function () {
    $file = app(FileStore::class)->store("hello\n", 'note.txt', 'text/plain');

    $this->actingAs($this->editor)->withHeader('User-Agent', 'pyRevit/5.0')->get($file->url())->assertOk();

    $access = FileAccess::query()->sole();

    expect($access->file->is($file))->toBeTrue()
        ->and($access->user?->is($this->editor))->toBeTrue()
        ->and($access->channel)->toBe('web')
        ->and($access->user_agent)->toBe('pyRevit/5.0')
        ->and($access->bytes)->toBe(6)
        ->and($file->accesses()->count())->toBe(1);
});
