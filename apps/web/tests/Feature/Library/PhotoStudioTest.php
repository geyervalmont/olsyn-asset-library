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
    Livewire::actingAs($this->editor)->test('pages::materials.photo-studio')
        ->set('importRepresentation', (string) $rep->id)->call('import')->assertHasNoErrors()
        ->set('tint', '#a04422')->set('tint_amount', 25)->call('adjust')->assertHasNoErrors()
        ->call('fork')->assertHasNoErrors()->assertSee('colourway');
    expect(StudioDraft::query()->where('state', 'active')->count())->toBe(2);
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
