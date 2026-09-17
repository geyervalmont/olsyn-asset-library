<?php

use App\Library\Embeddings\EmbeddingInput;
use App\Library\Embeddings\TitanMultimodalEmbeddingProvider;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\MockHandler;
use Aws\Result;

function titanProviderWith(MockHandler $handler): TitanMultimodalEmbeddingProvider
{
    $provider = new TitanMultimodalEmbeddingProvider;
    $client = new BedrockRuntimeClient([
        'version' => 'latest',
        'region' => 'ap-southeast-2',
        'credentials' => ['key' => 'test', 'secret' => 'test'],
        'handler' => $handler,
    ]);

    (new ReflectionProperty($provider, 'client'))->setValue($provider, $client);

    return $provider;
}

it('accepts text-only embedding input', function () {
    config()->set('opal.embeddings.dimensions', 2);
    $handler = new MockHandler([new Result([
        'body' => json_encode(['embedding' => [0.25, 0.75]], JSON_THROW_ON_ERROR),
    ])]);

    $values = titanProviderWith($handler)->embed(new EmbeddingInput(text: 'acoustic wall panel'));
    $payload = json_decode((string) $handler->getLastRequest()->getBody(), true, flags: JSON_THROW_ON_ERROR);

    expect($values)->toBe([0.25, 0.75])
        ->and($payload['inputText'])->toBe('acoustic wall panel')
        ->and($payload)->not->toHaveKey('inputImage');
});

it('accepts image-only embedding input', function () {
    config()->set('opal.embeddings.dimensions', 2);
    $handler = new MockHandler([new Result([
        'body' => json_encode(['embedding' => [0.1, 0.9]], JSON_THROW_ON_ERROR),
    ])]);

    $values = titanProviderWith($handler)->embed(new EmbeddingInput(image: 'preview bytes'));
    $payload = json_decode((string) $handler->getLastRequest()->getBody(), true, flags: JSON_THROW_ON_ERROR);

    expect($values)->toBe([0.1, 0.9])
        ->and($payload['inputImage'])->toBe(base64_encode('preview bytes'))
        ->and($payload)->not->toHaveKey('inputText');
});
