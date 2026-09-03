<?php

namespace App\Library\Legacy;

use Illuminate\Support\Str;

/**
 * Cleans colourway names as they came out of the legacy library, where the
 * product name, supplier codes and texture-map suffixes leaked into them.
 */
final class LegacyNames
{
    /** Tokens that name a texture map, not a colour. */
    private const MAP_SUFFIXES = [
        'alpha', 'base', 'disp', 'displacement', 'mtl', 'metal', 'metallic', 'nrm', 'normal', 'rough', 'roughness',
        'ao', 'albedo', 'diffuse', 'height', 'bump', 'gloss', 'glossiness', 'spec', 'specular', 'opacity',
    ];

    public static function colourway(?string $raw, string $product, ?string $supplierCode = null, ?string $supplierName = null): ?string
    {
        $name = trim((string) $raw);

        if ($name === '') {
            return null;
        }

        // "12mm Solid PET Emboss Panel Celium - Zintra 12mm Solid PET - Bark" → "Bark"
        if (str_contains($name, ' - ')) {
            $segments = array_values(array_filter(array_map('trim', explode(' - ', $name))));
            $last = end($segments);

            if ($last !== false && str_word_count($last) <= 4 && ! self::same($last, $product)) {
                $name = $last;
            }
        }

        $tokens = array_values(array_filter(preg_split('/\s+/', $name) ?: [], fn (string $token): bool => trim($token) !== ''));
        $tokens = self::withoutProductPhrase($tokens, $product);
        $supplierTokens = array_map(self::key(...), preg_split('/[\s\-_]+/', (string) $supplierName) ?: []);
        $code = self::key((string) $supplierCode);

        $kept = [];

        foreach ($tokens as $index => $token) {
            $key = self::key($token);

            if ($key === '') {
                $kept[] = $token;

                continue;
            }

            if ($supplierTokens !== [''] && in_array($key, $supplierTokens, true) && count($tokens) > 1) {
                continue;
            }

            // A trailing supplier code ("Ashen 001") or a texture-map suffix ("duckegg NRM").
            if ($index === count($tokens) - 1 && count($tokens) > 1 && ($key === $code || in_array($key, self::MAP_SUFFIXES, true))) {
                continue;
            }

            $kept[] = $token;
        }

        $kept = self::collapseRepeats($kept);
        $cleaned = trim(implode(' ', $kept));

        if ($cleaned === '') {
            $cleaned = $name;
        }

        return $cleaned === strtolower($cleaned) ? Str::title($cleaned) : $cleaned;
    }

    /**
     * Drop the product name when it leads ("Oakley Duckegg", "oakley-fr duckegg"),
     * trails ("Duckegg Oakley"), or splits two identical halves
     * ("Grafito Grip Aspley Grafito Grip"). Anywhere else it is part of the name.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private static function withoutProductPhrase(array $tokens, string $product): array
    {
        $productKeys = array_values(array_filter(array_map(self::key(...), preg_split('/[\s\-_]+/', $product) ?: [])));
        $keys = array_map(self::key(...), $tokens);
        $length = count($productKeys);

        if ($length === 0 || count($tokens) <= $length) {
            return $tokens;
        }

        $matches = fn (int $at): bool => array_slice($keys, $at, $length) === $productKeys;
        $leadsWithSuffix = $length === 1 && strlen($productKeys[0]) > 3 && str_starts_with($keys[0], $productKeys[0]);

        if ($matches(0) || $leadsWithSuffix) {
            return array_slice($tokens, $length);
        }

        $tail = count($tokens) - $length;

        if ($matches($tail)) {
            return array_slice($tokens, 0, $tail);
        }

        for ($at = 1; $at < $tail; $at++) {
            if ($matches($at) && array_slice($keys, 0, $at) === array_slice($keys, $at + $length)) {
                return array_slice($tokens, 0, $at);
            }
        }

        return $tokens;
    }

    /**
     * "Grafito Grip Grafito Grip" → "Grafito Grip"; "2915 2915" → "2915"; "Oak Oak Natural" → "Oak Natural".
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private static function collapseRepeats(array $tokens): array
    {
        $count = count($tokens);

        if ($count >= 2 && $count % 2 === 0) {
            $half = (int) ($count / 2);
            $first = array_map(self::key(...), array_slice($tokens, 0, $half));
            $second = array_map(self::key(...), array_slice($tokens, $half));

            if ($first === $second) {
                return array_slice($tokens, 0, $half);
            }
        }

        $result = [];

        foreach ($tokens as $token) {
            if ($result !== [] && self::key(end($result)) === self::key($token)) {
                continue;
            }

            $result[] = $token;
        }

        return $result;
    }

    private static function same(string $a, string $b): bool
    {
        return self::key($a) === self::key($b);
    }

    private static function key(string $value): string
    {
        return strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '', $value)));
    }
}
