<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::clear();
    app(SyncRolesAndPermissions::class)->handle();
    $tenant = Tenant::factory()->create();
    $tenant->makeCurrent();
    $this->user = User::factory()->withTenant($tenant, Role::Viewer)->create();

    $this->releaseManifest = [
        'version' => '0.1.0.7',
        'published_at' => '2026-09-09T05:00:00Z',
        'revit_version' => 2027,
        'minimum_revit' => 2027,
        'target_framework' => 'net10.0-windows7.0',
        'runtime' => '.NET 10',
        'verification' => 'host',
        'commit' => 'abc1234',
        'notes' => 'Native preview.',
        'installer' => ['name' => 'OPAL-Revit-Setup.exe', 'sha256' => str_repeat('a', 64), 'bytes' => 2_500_000],
        'package' => ['name' => 'OPAL-Revit-2027-Package.zip', 'sha256' => str_repeat('b', 64), 'bytes' => 1_500_000],
    ];

    Http::fake(fn () => Http::response($this->releaseManifest));
});

test('the native Revit installer is downloadable from settings', function () {
    $this->actingAs($this->user)
        ->get(route('revit.edit'))
        ->assertOk()
        ->assertSee('OPAL for Revit')
        ->assertSee('Revit 2025')
        ->assertSee('Revit 2026')
        ->assertSee('Revit 2027')
        ->assertSee('Version 0.1.0.7')
        ->assertSee('data-test="download-revit"', false)
        ->assertSee(route('revit.download.legacy', ['channel' => 'development', 'asset' => 'installer']), false);
});

test('clients discover updates through OPAL and downloads stay on an OPAL address', function () {
    $release = $this->getJson('/api/v1/client-releases/revit/development')
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=60, public, stale-if-error=600')
        ->assertJsonPath('version', '0.1.0.7')
        ->assertJsonPath('minimum_revit', 2027)
        ->assertJsonPath('package.sha256', str_repeat('b', 64))
        ->json();

    expect($release['installer']['url'])->toBe(route('revit.download.legacy', ['channel' => 'development', 'asset' => 'installer']))
        ->and($release['package']['url'])->toBe(route('revit.download.legacy', ['channel' => 'development', 'asset' => 'package']));

    $this->get('/downloads/revit/development/installer')
        ->assertRedirect('https://github.com/geyervalmont/olsyn-asset-library/releases/download/revit-development/OPAL-Revit-Setup.exe');
});

test('each Revit host discovers only its exact update package', function () {
    $manifest = $this->releaseManifest;
    $manifest['revit_version'] = 2025;
    $manifest['minimum_revit'] = 2025;
    $manifest['target_framework'] = 'net8.0-windows7.0';
    $manifest['runtime'] = '.NET 8';
    $manifest['verification'] = 'ci';
    $manifest['package']['name'] = 'OPAL-Revit-2025-Package.zip';
    $this->releaseManifest = $manifest;

    $release = $this->getJson('/api/v1/client-releases/revit/2025/development')
        ->assertOk()
        ->assertJsonPath('revit_version', 2025)
        ->json();

    expect($release['package']['url'])->toBe(route('revit.download', [
        'revitVersion' => 2025,
        'channel' => 'development',
        'asset' => 'package',
    ]));

    $this->get('/downloads/revit/2025/development/package')
        ->assertRedirect('https://github.com/geyervalmont/olsyn-asset-library/releases/download/revit-development/OPAL-Revit-2025-Package.zip');

    $this->getJson('/api/v1/client-releases/revit/2024/development')->assertNotFound();
});

test('unknown release channels and malformed manifests fail closed', function () {
    $this->getJson('/api/v1/client-releases/revit/nightly')->assertNotFound();

    Cache::clear();
    $this->releaseManifest = ['version' => 'broken'];

    $this->getJson('/api/v1/client-releases/revit/development')
        ->assertServiceUnavailable()
        ->assertJsonPath('message', 'The Revit release manifest is missing [published_at].');
});
