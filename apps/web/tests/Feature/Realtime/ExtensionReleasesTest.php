<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

test('tagged extension versions are discovered independently and cached', function () {
    Cache::forget('extension-releases.v1');
    $release = fn ($tag): array => ['tag_name' => $tag, 'draft' => false, 'prerelease' => false, 'published_at' => '2026-09-24T00:00:00Z', 'html_url' => 'https://github.com/example/'.$tag, 'assets' => [['name' => 'setup.zip', 'size' => 100, 'browser_download_url' => 'https://github.com/example/setup.zip']]];
    Http::fake(['api.github.com/*' => Http::response([$release('revit/v0.2.0'), $release('revit/v0.3.0'), $release('omniverse/v0.1.0'), $release('revit-latest')])]);
    $this->getJson('/api/v1/client-releases')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.version', '0.3.0')->assertJsonPath('data.2.client', 'omniverse');
    $this->getJson('/api/v1/client-releases')->assertOk();
    Http::assertSentCount(1);
});
