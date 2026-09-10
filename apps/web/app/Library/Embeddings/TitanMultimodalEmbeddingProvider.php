<?php

namespace App\Library\Embeddings;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use JsonException;
use RuntimeException;

/**
 * Text and rendered material previews share one Titan vector space, enabling
 * text-to-material and material-to-material retrieval with the same index.
 */
final class TitanMultimodalEmbeddingProvider implements EmbeddingProvider
{
    private BedrockRuntimeClient $client;

    public function __construct()
    {
        $this->client = new BedrockRuntimeClient([
            'version' => 'latest',
            'region' => (string) config('opal.embeddings.region'),
        ]);
    }

    public function name(): string
    {
        return 'bedrock';
    }

    public function model(): string
    {
        return (string) config('opal.embeddings.model');
    }

    public function dimensions(): int
    {
        return (int) config('opal.embeddings.dimensions');
    }

    /**
     * @return list<float>
     */
    public function embed(EmbeddingInput $input): array
    {
        $payload = [
            'inputText' => $input->text,
            'embeddingConfig' => ['outputEmbeddingLength' => $this->dimensions()],
        ];

        if ($input->image !== null) {
            if (strlen($input->image) > 25 * 1024 * 1024) {
                throw new RuntimeException('The embedding preview exceeds Titan\'s 25 MB input limit.');
            }

            $payload['inputImage'] = base64_encode($input->image);
        }

        try {
            $result = $this->client->invokeModel([
                'modelId' => $this->model(),
                'contentType' => 'application/json',
                'accept' => 'application/json',
                'body' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]);
            $decoded = json_decode((string) $result['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The embedding request or response was not valid JSON.', previous: $exception);
        }

        $values = $decoded['embedding'] ?? null;

        if (! is_array($values)) {
            throw new RuntimeException('The embedding provider returned no vector.');
        }

        /** @var list<float|int> $values */
        EmbeddingVector::literal($values, $this->dimensions());

        return array_map(fn (float|int $value): float => (float) $value, $values);
    }
}
