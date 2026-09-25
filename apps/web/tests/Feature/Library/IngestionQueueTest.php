<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Actions\Drives\ManageIntake;
use App\Enums\Role;
use App\Events\Drives\IntakeSubmitted;
use App\Models\DriveIntakeSession;
use App\Models\Material;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\LibrarySeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LibrarySeeder::class);
    app(SyncRolesAndPermissions::class)->handle();
    $this->tenant = Tenant::factory()->create();
    $this->tenant->makeCurrent();
    $this->user = User::factory()->withTenant($this->tenant, Role::Editor)->create();
    $this->reviewer = User::factory()->withTenant($this->tenant, Role::Editor)->create();
    Storage::fake(config('opal.drive.intake_disk'));
    $this->intake = app(ManageIntake::class);
});

afterEach(fn () => Tenant::forgetCurrent());

test('the root upload folder is opt-in for capable clients, idempotent, private and non-expiring', function () {
    Sanctum::actingAs($this->user, ['drive:read', 'drive:write']);
    $this->getJson('/api/v1/drive/manifest')->assertJsonPath('upload_enabled', true)->assertJsonPath('upload', null);
    expect(DriveIntakeSession::count())->toBe(0); // GET never creates a batch.
    $created = $this->postJson('/api/v1/drive/intake/inbox')->assertOk()->assertJsonPath('data.path', '/upload')->json('data');
    $this->postJson('/api/v1/drive/intake/inbox')->assertOk()->assertJsonPath('data.id', $created['id']);
    expect(DriveIntakeSession::count())->toBe(1);
    $this->travel(35)->days();
    $this->getJson('/api/v1/drive/manifest')->assertJsonPath('upload.id', $created['id'])->assertJsonPath('upload.expires_at', null)->assertJsonCount(0, 'incoming');
    $this->postJson('/api/v1/drive/intake/inbox')->assertJsonPath('data.id', $created['id']);
    Sanctum::actingAs($this->reviewer, ['*']);
    $this->getJson('/api/v1/drive/manifest')->assertJsonPath('upload', null);
    Sanctum::actingAs($this->user, ['drive:read']);
    $this->getJson('/api/v1/drive/manifest')->assertJsonPath('upload_enabled', false)->assertJsonPath('upload', null);
    $this->postJson('/api/v1/drive/intake/inbox')->assertForbidden();
});

test('nested arbitrary source files reach the queue intact and only submission shares them with workspace reviewers', function () {
    Event::fake([IntakeSubmitted::class]);
    $batch = $this->intake->inbox($this->user);
    $file = $this->intake->reserve($batch, 'Supplier/Stone/4K/material.mdl', 8, hash('sha256', 'mdl data'));
    $download = route('ingestion.download', ['batch' => $batch->uuid, 'file' => $file->uuid]);
    $this->actingAs($this->user)->get($download)->assertNotFound();
    $this->tenant->makeCurrent();
    Livewire::actingAs($this->user)->test('pages::ingestion')->call('selectBatch', $batch->uuid)
        ->assertSee('Awaiting upload')->call('submitBatch')->assertHasErrors('batch');
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, 'mdl data');
    rewind($stream);
    $this->intake->upload($batch, $file, $stream);
    fclose($stream);
    Livewire::actingAs($this->user)->test('pages::ingestion')->call('selectBatch', $batch->uuid)
        ->assertSee('Supplier/Stone/4K/material.mdl')->assertSee('Received')
        ->set('batchName', 'Stone supplier delivery')->call('renameBatch')->assertSee('Stone supplier delivery');
    $this->actingAs($this->user)->get($download)->assertOk()->assertHeader('Content-Type', 'application/octet-stream')->assertStreamedContent('mdl data');
    $this->actingAs($this->reviewer)->get($download)->assertNotFound();
    $this->tenant->makeCurrent();
    expect(fn () => Livewire::actingAs($this->reviewer)->test('pages::ingestion')->call('selectBatch', $batch->uuid))->toThrow(ModelNotFoundException::class);
    Livewire::actingAs($this->user)->test('pages::ingestion')->call('selectBatch', $batch->uuid)->call('submitBatch')->assertSee('Queued for review');
    Event::assertDispatchedTimes(IntakeSubmitted::class, 1);
    $this->intake->submit($batch->fresh());
    Event::assertDispatchedTimes(IntakeSubmitted::class, 1);
    $this->actingAs($this->reviewer)->get($download)->assertOk()->assertStreamedContent('mdl data');
    $this->tenant->makeCurrent();
    Livewire::actingAs($this->reviewer)->test('pages::ingestion')->call('selectBatch', $batch->uuid)->assertSee('Stone supplier delivery')->assertDontSee('Rename');
    expect(fn () => Livewire::actingAs($this->reviewer)->test('pages::ingestion')->set('batch', $batch->uuid)->set('batchName', 'Not mine')->call('renameBatch'))->toThrow(ModelNotFoundException::class);
    expect($this->intake->inbox($this->user)->uuid)->not->toBe($batch->uuid)->and(Material::count())->toBe(0);
    Storage::disk($file->disk)->assertExists($file->object_key);
});

test('the queue cannot cross workspaces or expose files to a viewer, including on Livewire actions', function () {
    $batch = $this->intake->inbox($this->user);
    $viewer = User::factory()->withTenant($this->tenant, Role::Viewer)->create();
    Livewire::actingAs($viewer)->test('pages::ingestion')->assertForbidden();
    Sanctum::actingAs($viewer, ['*']);
    $this->postJson('/api/v1/drive/intake/inbox')->assertForbidden();
    $otherTenant = Tenant::factory()->create();
    $outsider = User::factory()->withTenant($otherTenant, Role::Admin)->create();
    $otherTenant->makeCurrent();
    $batch->update(['status' => 'submitted']);
    expect(DriveIntakeSession::query()->visibleTo($outsider)->count())->toBe(0);
    expect(fn () => Livewire::actingAs($outsider)->test('pages::ingestion')->call('selectBatch', $batch->uuid))->toThrow(ModelNotFoundException::class);
});

test('legacy Incoming batches remain private and a fresh inbox follows closure without removing source files', function () {
    $old = DriveIntakeSession::create(['user_id' => $this->user->id, 'name' => 'Legacy private batch', 'expires_at' => now()->addDay(), 'status' => 'submitted']);
    expect(DriveIntakeSession::query()->visibleTo($this->reviewer)->count())->toBe(0);
    $batch = $this->intake->inbox($this->user);
    $file = $this->intake->reserve($batch, 'nested/source.exr', 4, hash('sha256', 'data'));
    Livewire::actingAs($this->user)->test('pages::ingestion')->call('selectBatch', $batch->uuid)->call('closeBatch')->assertSee('Closed');
    expect($batch->fresh()->files()->count())->toBe(1)
        ->and($this->intake->inbox($this->user)->uuid)->not->toBe($batch->uuid);
    $legacy = $this->intake->create($this->user, 'Legacy client');
    Sanctum::actingAs($this->user, ['*']);
    $this->getJson('/api/v1/drive/manifest')->assertJsonPath('incoming.0.path', '/Incoming/'.$legacy->uuid)->assertJsonPath('upload.path', '/upload');
});
