<?php

namespace App\Support;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ClientReleaseCatalog
{
    /**
     * @return array<string, mixed>
     */
    public function latest(string $client, string $channel): array
    {
        $config = $this->config($client, $channel);
        $cacheKey = "client-release.{$client}.{$channel}";

        /** @var array<string, mixed> $manifest */
        $manifest = Cache::remember($cacheKey, now()->addMinutes(2), function () use ($config): array {
            $response = Http::acceptJson()->timeout(10)->retry(2, 200)->get($this->githubUrl(
                $config['repository'],
                $config['tag'],
                'release-manifest.json',
            ));

            try {
                $response->throw();
            } catch (RequestException $exception) {
                throw new RuntimeException('The Revit release service is temporarily unavailable.', previous: $exception);
            }

            $data = $response->json();

            if (! is_array($data)) {
                throw new RuntimeException('The Revit release manifest is invalid.');
            }

            return $data;
        });

        foreach (['version', 'published_at', 'minimum_revit', 'installer', 'package'] as $required) {
            if (! array_key_exists($required, $manifest)) {
                throw new RuntimeException("The Revit release manifest is missing [{$required}].");
            }
        }

        return [
            ...Arr::except($manifest, ['installer', 'package']),
            'client' => $client,
            'channel' => $channel,
            'channel_label' => $config['label'],
            'installer' => [
                ...$this->asset($manifest['installer'], 'installer'),
                'url' => route('revit.download', ['channel' => $channel, 'asset' => 'installer']),
            ],
            'package' => [
                ...$this->asset($manifest['package'], 'package'),
                'url' => route('revit.download', ['channel' => $channel, 'asset' => 'package']),
            ],
        ];
    }

    public function downloadUrl(string $client, string $channel, string $asset): string
    {
        abort_unless(in_array($asset, ['installer', 'package'], true), 404);

        $config = $this->config($client, $channel);
        $release = $this->latest($client, $channel);
        $name = $release[$asset]['name'] ?? null;

        abort_unless(is_string($name) && $name !== '', 503, 'The requested client download is unavailable.');

        return $this->githubUrl($config['repository'], $config['tag'], $name);
    }

    /**
     * @return array{repository: string, tag: string, label: string}
     */
    private function config(string $client, string $channel): array
    {
        $clientConfig = config("opal.clients.{$client}");
        $channelConfig = config("opal.clients.{$client}.channels.{$channel}");

        abort_unless(is_array($clientConfig) && is_array($channelConfig), 404);

        return [
            'repository' => (string) $clientConfig['repository'],
            'tag' => (string) $channelConfig['tag'],
            'label' => (string) $channelConfig['label'],
        ];
    }

    /**
     * @return array{name: string, sha256: string, bytes: int}
     */
    private function asset(mixed $asset, string $kind): array
    {
        if (! is_array($asset)
            || ! is_string($asset['name'] ?? null)
            || basename($asset['name']) !== $asset['name']
            || str_contains($asset['name'], '\\')
            || ! is_string($asset['sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/i', $asset['sha256']) !== 1
            || ! is_numeric($asset['bytes'] ?? null)
            || (int) $asset['bytes'] < 1) {
            throw new RuntimeException("The Revit {$kind} entry is invalid.");
        }

        return [
            'name' => $asset['name'],
            'sha256' => strtolower($asset['sha256']),
            'bytes' => (int) ($asset['bytes'] ?? 0),
        ];
    }

    private function githubUrl(string $repository, string $tag, string $asset): string
    {
        return sprintf(
            'https://github.com/%s/releases/download/%s/%s',
            trim($repository, '/'),
            rawurlencode($tag),
            rawurlencode($asset),
        );
    }
}
