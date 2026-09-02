<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Enums\Role;
use App\Library\Drives\DriveNamespace;
use App\Models\Drive;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\LibrarySeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(LibrarySeeder::class);
    app(SyncRolesAndPermissions::class)->handle();
});

afterEach(fn () => Tenant::forgetCurrent());

test('prismfs fetches a drive manifest with its bearer token and gets 304 when unchanged', function () {
    $drive = Drive::factory()->create();
    $url = route('prismfs.drives.manifest', $drive);

    $this->get($url)->assertStatus(401)->assertHeader('WWW-Authenticate');

    $token = $drive->issueToken();

    expect($token)->toStartWith('opal_')
        ->and($drive->fresh()?->access_token_hash)->toBe(hash('sha256', $token))
        ->and($drive->fresh()?->token_issued_at)->not->toBeNull()
        ->and($drive->tokenMatches('opal_wrong'))->toBeFalse();

    $this->withToken('opal_wrong')->get($url)->assertStatus(401);

    $response = $this->withToken($token)->get($url)->assertOk()->assertHeader('Content-Type', 'application/yaml; charset=utf-8');
    $etag = $response->headers->get('ETag');

    expect($response->getContent())->toBe(app(DriveNamespace::class)->toYaml($drive))
        ->and($etag)->toStartWith('"');

    $this->withToken($token)->withHeaders(['If-None-Match' => $etag])->get($url)->assertStatus(304);
    $this->withToken($token)->withHeaders(['If-None-Match' => '"stale", '.$etag])->get($url)->assertStatus(304);
    $this->withToken($token)->withHeaders(['If-None-Match' => '"stale"'])->get($url)->assertOk();

    $rotated = $drive->issueToken();

    $this->withToken($token)->get($url)->assertStatus(401);
    $this->withToken($rotated)->get($url)->assertOk();

    $drive->forceFill(['is_active' => false])->save();
    $this->withToken($rotated)->get($url)->assertStatus(401);
});

test('admins issue and rotate tokens from the drives page and see them once', function () {
    $tenant = Tenant::factory()->create();
    $admin = User::factory()->withTenant($tenant, Role::Admin)->create();
    $drive = Drive::factory()->create(['name' => 'Studio share']);
    $tenant->makeCurrent();

    $page = Livewire::actingAs($admin)
        ->test('pages::drives.index')
        ->assertSee('Issue token')
        ->call('issueToken', $drive->getKey())
        ->assertSee('data-test="issued-token"', false)
        ->assertSee('prismfs mount --manifest-url')
        ->assertSee('Rotate token');

    $token = $page->get('issuedToken');

    expect($drive->fresh()?->tokenMatches($token))->toBeTrue();
});
