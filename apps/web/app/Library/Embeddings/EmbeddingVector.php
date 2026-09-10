<?php

namespace App\Library\Embeddings;

use InvalidArgumentException;

final class EmbeddingVector
{
    /**
     * @param  list<float|int>  $values
     */
    public static function literal(array $values, int $dimensions): string
    {
        if (count($values) !== $dimensions) {
            throw new InvalidArgumentException(sprintf('Embedding has %d dimensions; expected %d.', count($values), $dimensions));
        }

        $formatted = array_map(function (float|int $value): string {
            $value = (float) $value;

            if (! is_finite($value)) {
                throw new InvalidArgumentException('Embedding values must be finite numbers.');
            }

            return sprintf('%.9G', $value);
        }, $values);

        return '['.implode(',', $formatted).']';
    }
}
