<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Enums\Role;
use App\Models\MaterialCommission;
use App\Models\StudioDraft;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\LibrarySeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['synthesis.disk' => 'local', 'olsyn_access.enabled' => false]);
    Storage::fake('local');
    $this->seed(LibrarySeeder::class);
    app(SyncRolesAndPermissions::class)->handle();
    $this->tenant = Tenant::factory()->create();
    $this->editor = User::factory()->withTenant($this->tenant, Role::Editor)->create(['workos_id' => 'user_bender']);
    $this->editor->forceFill(['current_tenant_id' => $this->tenant->id])->save();
    $this->bearer = $this->editor->createToken('Bender', ['bender:materials'])->plainTextToken;
    $this->payload = ['brief' => 'Warm coherent limestone', 'idempotency_key' => 'building-123', 'bender_job_id' => (string) Str::uuid(), 'trace_id' => str_repeat('a', 32)];
    $this->png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j5k0AAAAASUVORK5CYII=');
});

afterEach(fn () => Tenant::forgetCurrent());

test('a scoped connection exposes verified identity and rejects unrelated tokens', function () {
    $this->withToken($this->bearer)->getJson('/api/v1/bender/identity')->assertOk()->assertJsonPath('subject', 'user_bender');
    $this->getJson('/api/v1/materials')->assertForbidden();
    auth()->forgetGuards();
    $other = $this->editor->createToken('Other', ['read'])->plainTextToken;
    $this->withToken($other)->getJson('/api/v1/bender/identity')->assertForbidden();
});

test('commission delivery is idempotent and private with traceable reusable drafts', function () {
    $url = '/api/v1/bender/commissions';
    $id = $this->withToken($this->bearer)->postJson($url, $this->payload)->assertCreated()->assertJsonPath('decision', 'commission')->json('id');
    $this->postJson($url, $this->payload)->assertCreated()->assertJsonPath('id', $id);
    $this->postJson($url, [...$this->payload, 'brief' => 'Different'])->assertConflict();
    $this->postJson($url.'/'.$id.'/complete', ['width_mm' => 1000, 'height_mm' => 1000, 'normal_convention' => 'opengl', 'source_job_id' => (string) Str::uuid(), 'bender_artifact_ids' => [Str::uuid()->toString(), Str::uuid()->toString(), Str::uuid()->toString()]])->assertUnprocessable();
    foreach (['base_color', 'normal', 'roughness'] as $role) {
        $data = ['data' => base64_encode($this->png), 'sha256' => hash('sha256', $this->png)];
        $this->postJson($url.'/'.$id.'/artifacts/'.$role, $data)->assertOk();
        $this->postJson($url.'/'.$id.'/artifacts/'.$role, $data)->assertOk();
    }
    $this->postJson($url.'/'.$id.'/artifacts/base_color', ['data' => base64_encode($this->png.'changed'), 'sha256' => hash('sha256', $this->png.'changed')])->assertConflict();
    $data = ['width_mm' => 1000, 'height_mm' => 1000, 'normal_convention' => 'opengl', 'source_job_id' => (string) Str::uuid(), 'bender_artifact_ids' => [Str::uuid()->toString(), Str::uuid()->toString(), Str::uuid()->toString()]];
    $this->postJson($url.'/'.$id.'/complete', $data)->assertOk()->assertJsonPath('status', 'ready');
    $this->postJson($url.'/'.$id.'/complete', $data)->assertOk();
    expect(StudioDraft::count())->toBe(1)->and(StudioDraft::first()->state)->toBe('active');
    $this->getJson($url.'/'.$id.'/artifacts/normal')->assertOk()->assertHeader('Content-Type', 'image/png');
    $next = $this->postJson($url, [...$this->payload, 'idempotency_key' => 'another-building', 'bender_job_id' => (string) Str::uuid()])->assertCreated()->assertJsonPath('decision', 'reuse')->json('id');
    expect($next)->not->toBe($id)->and(MaterialCommission::find($next)->provenance['reused_commission_id'])->toBe($id);
    auth()->forgetGuards();
    $other = User::factory()->withTenant($this->tenant, Role::Editor)->create(['workos_id' => 'user_other']);
    $other->forceFill(['current_tenant_id' => $this->tenant->id])->save();
    $this->withToken($other->createToken('Bender', ['bender:materials'])->plainTextToken)->getJson($url.'/'.$id)->assertNotFound();
});

test('readers cannot commission and changing workspaces hides prior commissions', function () {
    $id = $this->withToken($this->bearer)->postJson('/api/v1/bender/commissions', $this->payload)->assertCreated()->json('id');
    $this->editor->forceFill(['current_tenant_id' => null])->save();
    $this->editor->tenants()->detach();
    auth()->forgetGuards();
    $this->getJson('/api/v1/bender/commissions/'.$id)->assertForbidden();
});

test('central contribute revocation applies even to an Opal super administrator', function () {
    $this->editor->forceFill(['is_super_admin' => true])->save();
    config(['olsyn_access.enabled' => true, 'olsyn_access.token' => str_repeat('a', 48), 'olsyn_access.decision_url' => 'https://central.example/decision']);
    Http::fake(['https://central.example/decision' => Http::response(['service' => 'opal', 'allowed' => true, 'permissions' => ['materials.view']])]);
    $this->withToken($this->bearer)->postJson('/api/v1/bender/commissions', $this->payload)->assertForbidden();
    expect(MaterialCommission::count())->toBe(0);
});
