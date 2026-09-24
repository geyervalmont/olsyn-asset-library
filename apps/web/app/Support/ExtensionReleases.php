<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ExtensionReleases
{
    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return Cache::remember('extension-releases.v1', now()->addMinutes(2), function (): array {
            $repository = config('opal.clients.revit.repository');
            $response = Http::acceptJson()->withHeaders(['User-Agent' => 'OPAL'])->timeout(10)
                ->get("https://api.github.com/repos/{$repository}/releases", ['per_page' => 100])->throw();
            /** @var list<array<string, mixed>> $releases */
            $releases = $response->json();

            return collect($releases)->filter(fn ($release) => ! $release['draft'] && ! $release['prerelease']
                && preg_match('~^(revit|omniverse|drive)/v[0-9]+\.[0-9]+\.[0-9]+$~', $release['tag_name']))
                ->map(fn ($release): array => [
                    'client' => explode('/', $release['tag_name'])[0], 'tag' => $release['tag_name'],
                    'version' => explode('/v', $release['tag_name'])[1], 'published_at' => $release['published_at'],
                    'url' => $release['html_url'],
                    'downloads' => collect((array) $release['assets'])->filter(fn ($asset) => preg_match('/\.(exe|zip|json)$/', $asset['name']) === 1)
                        ->map(fn ($asset): array => ['name' => $asset['name'], 'bytes' => $asset['size'], 'url' => $asset['browser_download_url']])->values()->all(),
                ])->sort(fn ($a, $b) => version_compare($b['version'], $a['version']))->values()->all();
        });
    }
}
