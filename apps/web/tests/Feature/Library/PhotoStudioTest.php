<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Actions\Materials\PromoteStudioRevision;
use App\Actions\Representations\CreateRepresentation;
use App\Actions\Representations\ReviewRepresentation;
use App\Enums\ReviewState;
use App\Enums\Role;
use App\Library\Studio\DraftStore;
use App\Library\Studio\SynthesisCompute;
use App\Models\Category;
use App\Models\StudioDraft;
use App\Models\SynthesisRun;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\LibrarySeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    config(['synthesis.disk' => 'local', 'synthesis.enabled' => true, 'synthesis.image' => 'worker@sha256:abc', 'synthesis.model_path' => 'models/test.tar', 'synthesis.model_sha256' => str_repeat('a', 64)]);
    Storage::fake('local');
    Storage::fake(config('opal.files_disk'));
    $this->seed(LibrarySeeder::class);
    app(SyncRolesAndPermissions::class)->handle();
    $this->tenant = Tenant::factory()->create();
    $this->editor = User::factory()->withTenant($this->tenant, Role::Editor)->create();
    $this->tenant->makeCurrent();
    $this->actingAs($this->editor);
    $this->store = app(DraftStore::class);
    $this->draft = $this->store->fromPhoto(UploadedFile::fake()->image('capture.jpg', 80, 64), 'Stone study');
    $this->revision = $this->draft->revisions()->sole();
});

afterEach(fn () => Tenant::forgetCurrent());

test('photo studio renders and private captures are restricted to their owner', function () {
    $this->get(route('materials.studio.photos'))->assertOk();
    $this->get(route('materials.studio.photos', ['draft' => $this->draft->uuid]))->assertOk();
    $url = route('studio-artifacts.show', ['revision' => $this->revision, 'role' => 'source']);
    $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
    $other = User::factory()->withTenant($this->tenant, Role::Editor)->create();
    $this->actingAs($other)->get($url)->assertNotFound();
    $this->get(route('materials.studio.photos', ['draft' => $this->draft->uuid]))->assertNotFound();
});

test('draft changes preserve history and reject a stale browser', function () {
    $document = array_merge($this->revision->document, ['width_mm' => 400]);
    $next = $this->store->revise($this->draft, $this->revision->id, $document);
    expect($this->draft->fresh()->head_id)->toBe($next->id)
        ->and($this->revision->fresh()->document['width_mm'])->toBeNull();
    expect(fn () => $this->store->revise($this->draft, $this->revision->id, $document))->toThrow(ValidationException::class);
});

test('generation is idempotent per revision and pins the execution configuration', function () {
    $run = $this->store->generate($this->revision);
    config(['synthesis.model_sha256' => str_repeat('b', 64)]);
    expect($this->store->generate($this->revision)->id)->toBe($run->id)
        ->and($run->runtime('model_sha256'))->toBe(str_repeat('a', 64))
        ->and(SynthesisRun::count())->toBe(1)
        ->and($run->toArray())->not->toHaveKey('worker_token');
    config(['synthesis.daily_runs' => 1]);
    $next = $this->store->revise($this->draft, $this->revision->id, $this->revision->document);
    expect(fn () => $this->store->generate($next))->toThrow(ValidationException::class);
});

test('worker tokens cannot cross jobs and cancellation closes callbacks', function () {
    $run = $this->store->generate($this->revision);
    $url = '/api/synthesis-runs/'.$run->uuid.'/source';
    $this->withToken('wrong')->getJson($url)->assertUnauthorized();
    $this->withToken($run->worker_token)->get($url)->assertOk();
    $run->update(['status' => 'cancelled']);
    $this->withToken($run->worker_token)->getJson($url)->assertGone();
});

test('late completion updates only its revision and requires every material map', function () {
    $run = $this->store->generate($this->revision);
    $new = $this->store->revise($this->draft, $this->revision->id, array_merge($this->revision->document, ['cleanup' => 20]));
    $payload = ['normal_convention' => 'opengl', 'model_sha256' => str_repeat('a', 64), 'chord_revision' => 'test', 'seconds' => 1];
    $url = '/api/synthesis-runs/'.$run->uuid.'/complete';
    $this->withToken($run->worker_token)->postJson($url, $payload)->assertUnprocessable();
    $maps = array_fill_keys(DraftStore::MAPS, $this->revision->document['source']);
    $run->update(['artifacts' => $maps]);
    config(['synthesis.model_sha256' => str_repeat('b', 64)]);
    $this->withToken($run->worker_token)->postJson($url, $payload)->assertOk();
    $this->withToken($run->worker_token)->postJson($url, $payload)->assertOk();
    expect($this->draft->fresh()->head_id)->toBe($new->id)
        ->and($new->fresh()->artifacts)->toBeNull()
        ->and($this->revision->fresh()->artifacts)->toHaveKeys(DraftStore::MAPS)
        ->and($run->fresh()->runtime('model_sha256'))->toBe(str_repeat('a', 64));
});

test('height upload rejects lost precision and artifact retries are immutable', function () {
    $doc = array_merge($this->revision->document, ['resolution' => 32]);
    $revision = $this->store->revise($this->draft, $this->revision->id, $doc);
    $run = $this->store->generate($revision);
    $image = new Imagick;
    $image->newPseudoImage(32, 32, 'gradient:black-white');
    $image->setImageFormat('png');
    $image->setImageDepth(8);
    $upload = function (string $role, string $bytes) use ($run) {
        return $this->withToken($run->worker_token)->post('/api/synthesis-runs/'.$run->uuid.'/artifacts/'.$role, ['image' => UploadedFile::fake()->createWithContent('map.png', $bytes), 'sha256' => hash('sha256', $bytes)], ['Accept' => 'application/json']);
    };
    $upload('height', $image->getImageBlob())->assertUnprocessable();
    $upload('roughness', $image->getImageBlob())->assertOk();
    $upload('roughness', $image->getImageBlob())->assertOk();
    $image->negateImage(false);
    $upload('roughness', $image->getImageBlob())->assertConflict();
    $image->setImageDepth(16);
    $image->setOption('png:bit-depth', '16');
    $upload('height', $image->getImageBlob())->assertOk();
    expect($run->fresh()->artifacts['height']['bit_depth'])->toBe(16);
});

test('promotion creates one review candidate and leaves a fork editable', function () {
    $doc = array_merge($this->revision->document, ['width_mm' => 500, 'height_mm' => 500]);
    $revision = $this->store->revise($this->draft, $this->revision->id, $doc, array_fill_keys(DraftStore::MAPS, $this->revision->document['source']));
    $fork = $this->store->fork($revision);
    $action = app(PromoteStudioRevision::class);
    $data = ['mode' => 'new', 'name' => 'Stone study', 'category_id' => Category::query()->firstOrFail()->id];
    $rep = $action->handle($revision, $data, $this->editor);
    expect($action->handle($revision->fresh(), $data, $this->editor)->id)->toBe($rep->id)
        ->and($rep->review_state->value)->toBe('candidate')
        ->and($this->draft->fresh()->state)->toBe('promoted')
        ->and($fork->fresh()->state)->toBe('active');
});

test('allocator rejects an on-prem profile before creating a lease', function () {
    config(['synthesis.broker_secret' => 'test']);
    $run = $this->store->generate($this->revision);
    Http::fake(['*/profiles' => Http::response(['profiles' => [['name' => $run->runtime('profile'), 'provider' => 'onprem', 'gpu_class' => 'gpu']]])]);
    expect(fn () => app(SynthesisCompute::class)->allocate($run))->toThrow(RuntimeException::class);
    Http::assertSentCount(1);
});

test('discarded and expired jobs are closed without requesting compute', function () {
    $run = $this->store->generate($this->revision);
    $this->draft->update(['state' => 'discarded']);
    Http::fake();
    $this->artisan('opal:synthesis:reconcile')->assertSuccessful();
    expect($run->fresh()->status)->toBe('cancelled')->and($run->fresh()->released_at)->not->toBeNull();
    Http::assertNothingSent();
});

test('photo uploads persist in one request and redirect to a private draft', function () {
    $this->post(route('materials.studio.photos.upload'), ['name' => 'Phone photo', 'photo' => UploadedFile::fake()->image('phone.jpg', 120, 80)])->assertRedirect();
    expect(StudioDraft::query()->where('name', 'Phone photo')->sole()->user_id)->toBe($this->editor->id);
});

test('an existing map set can be imported, tinted and kept as a colourway draft', function () {
    $doc = array_merge($this->revision->document, ['width_mm' => 500, 'height_mm' => 500]);
    $revision = $this->store->revise($this->draft, $this->revision->id, $doc, array_fill_keys(DraftStore::MAPS, $this->revision->document['source']));
    $rep = app(PromoteStudioRevision::class)->handle($revision, ['mode' => 'new', 'name' => 'Existing stone', 'category_id' => Category::query()->firstOrFail()->id], $this->editor);
    $component = Livewire::actingAs($this->editor)->test('pages::materials.photo-studio')
        ->set('importRepresentation', (string) $rep->id)->call('import')->assertHasNoErrors()
        ->set('tint', '#a04422')->set('tint_amount', 25)->call('adjust')->assertHasNoErrors()
        ->call('fork')->assertHasNoErrors()->assertSee('colourway');
    expect(StudioDraft::query()->where('state', 'active')->count())->toBe(2);
    $fork = StudioDraft::query()->latest('id')->firstOrFail();
    $previous = $fork->revisions()->sole();
    config(['synthesis.daily_runs' => 0]);
    $component->call('generate')->assertHasErrors('generation');
    expect($fork->fresh()->head_id)->toBe($previous->id);
    config(['synthesis.daily_runs' => 30]);
    $component->call('generate')->assertHasNoErrors();
    expect($fork->fresh()->head_id)->not->toBe($previous->id)
        ->and($previous->fresh()->artifacts)->toBe($previous->artifacts)
        ->and($previous->fresh()->document)->toHaveKey('edits');
    $generated = $fork->revisions()->findOrFail($fork->fresh()->head_id);
    expect($generated->run)->not->toBeNull()->and($generated->document)->not->toHaveKey('edits');
});

test('approving a repaired surface replaces old LODs and applies scale only at review', function () {
    config(['opal.previews.auto_render' => false]);
    $maps = array_fill_keys(DraftStore::MAPS, $this->revision->document['source']);
    $revision = $this->store->revise($this->draft, $this->revision->id, array_merge($this->revision->document, ['width_mm' => 500, 'height_mm' => 500]), $maps);
    $rep = app(PromoteStudioRevision::class)->handle($revision, ['mode' => 'new', 'name' => 'Original', 'category_id' => Category::query()->firstOrFail()->id], $this->editor);
    app(ReviewRepresentation::class)->handle($rep, ReviewState::Approved);
    $high = app(CreateRepresentation::class)->handle($rep->variant, 'pbr', 2048, $rep->filesByRole());
    app(ReviewRepresentation::class)->handle($high, ReviewState::Approved);
    $fork = $this->store->fork($revision->fresh());
    $forkRevision = $fork->revisions()->sole();
    $edited = $this->store->revise($fork, $forkRevision->id, array_merge($forkRevision->document, ['width_mm' => 300, 'height_mm' => 400]), $maps);
    $repair = app(PromoteStudioRevision::class)->handle($edited, ['mode' => 'improve', 'name' => 'Repair', 'material_id' => $rep->variant->material_id, 'variant_id' => $rep->variant_id], $this->editor);
    expect((float) $rep->variant->fresh()->effectiveTileWidthMm())->toBe(500.0)
        ->and($high->fresh()->review_state)->toBe(ReviewState::Approved);
    app(ReviewRepresentation::class)->handle($repair, ReviewState::Approved);
    expect((float) $rep->variant->fresh()->effectiveTileWidthMm())->toBe(300.0)
        ->and((float) $rep->variant->fresh()->effectiveTileHeightMm())->toBe(400.0)
        ->and($high->fresh()->review_state)->toBe(ReviewState::Superseded)
        ->and($rep->fresh()->review_state)->toBe(ReviewState::Superseded);
});

test('alternative model completion preserves identity and rejects a different backend', function () {
    config(['synthesis.backend' => 'rgbx']);
    $run = $this->store->generate($this->revision);
    $run->update(['artifacts' => array_fill_keys(DraftStore::MAPS, $this->revision->document['source'])]);
    config(['synthesis.backend' => 'chord']);
    $payload = ['normal_convention' => 'opengl', 'model_sha256' => str_repeat('a', 64), 'model' => 'chord', 'model_revision' => 'pinned-code', 'seconds' => 3];
    $url = '/api/synthesis-runs/'.$run->uuid.'/complete';
    $this->withToken($run->worker_token)->postJson($url, $payload)->assertUnprocessable();
    $payload['model'] = 'rgbx';
    $payload['inference_steps'] = 50;
    $payload['inference_seconds'] = 2;
    $payload['normal_method'] = 'front-facing-planar-camera-space';
    $this->withToken($run->worker_token)->postJson($url, $payload)->assertOk();
    expect($run->fresh()->manifest['model'])->toBe('rgbx')
        ->and($run->fresh()->manifest['inference_seconds'])->toBe(2)
        ->and($run->fresh()->runtime('backend'))->toBe('rgbx');
});

test('pooled synthesis acquires only bounded AWS capacity with a stable attempt key', function () {
    config(['synthesis.profile' => 'aws-material-pool', 'synthesis.broker_secret' => 'test-secret', 'synthesis.broker_url' => 'https://broker.test']);
    $run = $this->store->generate($this->revision);
    Http::fake([
        'broker.test/acquire' => Http::response(['acquisition_id' => 'acq_test']),
        'broker.test/acquisitions/acq_test' => Http::sequence()
            ->push(['state' => 'fulfilled', 'allocation' => ['provider' => 'aws', 'allocation_id' => 'alloc_test', 'node_selector' => ['olsyn.com/burst-instance' => 'i-test']]])
            ->push(['state' => 'fulfilled', 'allocation' => ['provider' => 'onprem', 'allocation_id' => 'alloc_bad', 'node_selector' => ['node' => 'local']]]),
    ]);
    $allocation = app(SynthesisCompute::class)->allocate($run);
    expect($allocation['allocation_id'])->toBe('alloc_test');
    Http::assertSent(fn ($request) => $request->url() === 'https://broker.test/acquire'
        && $request['idempotency_key'] === 'opal-synthesis-'.$run->uuid
        && $request['budget_usd_per_hour'] === 3.5
        && $request['gpu_request']['max_gpus'] === 1);
    expect(fn () => app(SynthesisCompute::class)->allocate($run))->toThrow(RuntimeException::class, 'Invalid cloud acquisition allocation.');
});

test('zero metalness override preserves the inferred maps in the earlier revision', function () {
    $maps = array_fill_keys(DraftStore::MAPS, $this->revision->document['source']);
    $this->revision->update(['artifacts' => $maps]);
    Livewire::test('pages::materials.photo-studio', ['draft' => $this->draft->uuid])
        ->set('metallic', '0')->call('adjust')->assertHasNoErrors();
    $head = $this->draft->fresh()->revisions()->findOrFail($this->draft->fresh()->head_id);
    expect($head->artifacts['normal']['sha256'])->toBe($maps['normal']['sha256'])
        ->and($this->revision->fresh()->artifacts)->toBe($maps)
        ->and($head->document['edits'][0]['metallic'])->toBe('0');
    $image = new Imagick;
    $image->readImageBlob($this->store->bytes($head->artifacts['metallic']));
    expect($image->getImagePixelColor(0, 0)->getColor()['r'])->toBe(0);
});

test('worker progress preserves runtime, rejects invalid counts and ignores stale heartbeats', function () {
    $run = $this->store->generate($this->revision);
    $url = '/api/synthesis-runs/'.$run->uuid.'/progress';
    $this->withToken($run->worker_token)->postJson($url, ['stage' => 'estimating_material', 'completed' => 1, 'total' => 4, 'role' => 'normal'])->assertOk();
    $this->withToken($run->worker_token)->postJson($url, ['stage' => 'estimating_material'])->assertOk();
    $this->withToken($run->worker_token)->postJson($url, ['stage' => 'loading_models'])->assertOk();
    expect($run->fresh()->stage)->toBe('estimating_material')
        ->and($run->fresh()->manifest['progress']['completed'])->toBe(1)
        ->and($run->fresh()->runtime('model_sha256'))->toBe(str_repeat('a', 64));
    $this->withToken($run->worker_token)->postJson($url, ['stage' => 'uploading_maps', 'completed' => 5, 'total' => 4])->assertUnprocessable();
    $this->withToken($run->worker_token)->postJson($url, ['stage' => 'uploading_maps'])->assertOk();
    expect($run->fresh()->manifest['progress'])->not->toHaveKey('completed');
});

test('intermediate previews remain private and generation stays visible after edits', function () {
    $run = $this->store->generate($this->revision);
    $run->update(['stage' => 'estimating_material', 'status' => 'running', 'artifacts' => ['prepared' => $this->revision->document['source']]]);
    $url = route('studio-artifacts.show', ['revision' => $this->revision, 'role' => 'prepared']);
    $this->get($url)->assertOk();
    $this->tenant->makeCurrent();
    Livewire::actingAs($this->editor)->test('pages::materials.photo-studio', ['draft' => $this->draft->uuid])
        ->set('crop.size', 60)->assertHasNoErrors()
        ->assertSee('Estimating surface')->assertSee('This generation belongs to an earlier version.')
        ->call('generate')->assertHasNoErrors()
        ->call('cancel')->assertHasNoErrors();
    expect(SynthesisRun::count())->toBe(1)->and($run->fresh()->status)->toBe('cancelled');
    $other = User::factory()->withTenant($this->tenant, Role::Editor)->create();
    $this->actingAs($other)->get($url)->assertNotFound();
});

test('draft search and revision comparisons do not change the draft head', function () {
    $next = $this->store->revise($this->draft, $this->revision->id, array_merge($this->revision->document, ['cleanup' => 20]));
    $component = Livewire::actingAs($this->editor)->test('pages::materials.photo-studio', ['draft' => $this->draft->uuid])
        ->call('compare', $this->revision->id)->assertSee('Selected version')->assertHasNoErrors()
        ->set('search', substr($this->draft->uuid, 0, 5))->assertSee('1 draft')
        ->set('search', 'does-not-exist')->assertSee('No drafts found.');
    expect($this->draft->fresh()->head_id)->toBe($next->id);
    $foreign = $this->store->fromPhoto(UploadedFile::fake()->image('other.jpg'), 'Another draft');
    expect(fn () => $component->call('compare', $foreign->head_id))->toThrow(ModelNotFoundException::class);
});

test('appearance controls alter only base colour and keep reversible revisions', function () {
    $image = new Imagick;
    $image->newImage(64, 64, new ImagickPixel('#804020'), 'png');
    $base = $this->store->put($image->getImageBlob(), 'base_color');
    $image->clear();
    $image->newImage(64, 64, new ImagickPixel('#2080c0'), 'png');
    $prepared = $this->store->put($image->getImageBlob(), 'prepared');
    $maps = array_fill_keys(DraftStore::MAPS, $base) + ['prepared' => $prepared];
    $revision = $this->store->revise($this->draft, $this->revision->id, $this->revision->document, $maps);
    $component = Livewire::actingAs($this->editor)->test('pages::materials.photo-studio', ['draft' => $this->draft->uuid])
        ->set('photo_blend', 100)->call('adjust')->assertHasNoErrors();
    $edited = $this->draft->fresh()->head;
    $image->readImageBlob($this->store->bytes($edited->artifacts['base_color']));
    $pixel = $image->getImagePixelColor(0, 0)->getColor();
    expect($pixel['r'])->toBe(32)->and($pixel['g'])->toBe(128)->and($pixel['b'])->toBe(192);
    foreach (['normal', 'roughness', 'height', 'metallic'] as $role) {
        expect($edited->artifacts[$role])->toBe($maps[$role]);
    }
    $component->set('brightness', 120)->set('saturation', 0)->call('adjust')->assertHasNoErrors();
    $adjusted = $this->draft->fresh()->head;
    $image->readImageBlob($this->store->bytes($adjusted->artifacts['base_color']));
    $pixel = $image->getImagePixelColor(0, 0)->getColor();
    expect($pixel['r'])->toBe($pixel['g'])->and($pixel['g'])->toBe($pixel['b']);
    $component->call('restore', $revision->id)->assertHasNoErrors();
    expect($this->draft->fresh()->head->artifacts)->toBe($maps)
        ->and($revision->fresh()->artifacts)->toBe($maps);
});

test('draft thumbnails are bounded and retain owner authorization', function () {
    $image = new Imagick;
    $image->newImage(512, 256, new ImagickPixel('#886644'), 'png');
    $asset = $this->store->put($image->getImageBlob(), 'base_color');
    $this->revision->update(['artifacts' => ['base_color' => $asset]]);
    $url = route('studio-artifacts.show', ['revision' => $this->revision, 'role' => 'base_color', 'thumbnail' => 1]);
    $response = $this->get($url)->assertOk();
    $size = getimagesizefromstring($response->getContent());
    expect($size[0])->toBe(160)->and($size[1])->toBe(80);
    $other = User::factory()->withTenant($this->tenant, Role::Editor)->create();
    $this->actingAs($other)->get($url)->assertNotFound();
});
