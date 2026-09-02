<?php

namespace App\Library;

use Illuminate\Support\Str;

/**
 * Turns a human name into a stable, readable id segment.
 *
 * "H Collection" → H_COLLECTION, "Ashen, muted" → ASHEN_MUTED. Segments are
 * joined with "-" and words inside a segment with "_", so a code always splits
 * unambiguously on "-".
 */
final class CodeTokenizer
{
    public const MAX_LENGTH = 16;

    public static function token(string $value, int $maxLength = self::MAX_LENGTH): string
    {
        $token = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', Str::ascii($value)));
        $token = trim($token, '_');

        if ($token === '') {
            return 'X';
        }

        if (strlen($token) > $maxLength) {
            $token = rtrim(substr($token, 0, $maxLength), '_');
        }

        return $token;
    }
}
