<?php

namespace App\Library;

use App\Models\Alias;
use App\Models\Material;
use App\Models\Variant;
use Closure;

/**
 * Readable, immutable ids.
 *
 *   CPT-TARKETT-ACADEMIX          material: category code, supplier token, product token
 *   CPT-TARKETT-ACADEMIX-ASHEN    variant:  material code plus the variant token
 *
 * In-house materials use the IN_HOUSE supplier token. Tokens are proposed from
 * names and frozen at creation; a later rename never changes a code. Recoding
 * is an explicit action that keeps the old code as an alias.
 */
class MaterialCodes
{
    public const IN_HOUSE = 'OPAL';

    public const SEPARATOR = '-';

    public function materialCode(Material $material): string
    {
        $base = implode(self::SEPARATOR, [
            strtoupper($material->category->code),
            $material->supplier_id === null ? self::IN_HOUSE : $material->supplier->code,
            CodeTokenizer::token($material->name),
        ]);

        $taken = Material::query()
            ->where('code', 'like', $base.'%')
            ->whereKeyNot($material->getKey())
            ->pluck('code')
            ->merge(Alias::query()->where('code', 'like', $base.'%')->pluck('code'))
            ->all();

        return $this->nextFree($base, $taken);
    }

    /**
     * A token unique among the variant's siblings whose resulting code is
     * also free, so a variant code is always its material code plus token.
     */
    public function variantToken(Variant $variant): string
    {
        $materialCode = $variant->material->code;
        $prefix = $materialCode.self::SEPARATOR;

        $taken = Variant::query()
            ->where('material_id', $variant->material_id)
            ->where('token', 'like', $variant->token.'%')
            ->whereKeyNot($variant->getKey())
            ->pluck('token')
            ->merge(
                Alias::query()
                    ->where('code', 'like', $prefix.$variant->token.'%')
                    ->pluck('code')
                    ->map(fn (string $code): string => substr($code, strlen($prefix))),
            )
            ->all();

        return $this->nextFree($variant->token, $taken);
    }

    public function variantCode(Variant $variant): string
    {
        return $variant->material->code.self::SEPARATOR.$variant->token;
    }

    /**
     * The base itself, or the lowest free numeric suffix, decided in memory
     * from one over-fetched list of candidates.
     *
     * @param  array<int, string>  $taken
     */
    public function nextFree(string $base, array $taken): string
    {
        $used = [];

        foreach ($taken as $candidate) {
            if ($candidate === $base) {
                $used[$base] = true;
            } elseif (preg_match('/^'.preg_quote($base, '/').'_(\d+)$/', $candidate) === 1) {
                $used[$candidate] = true;
            }
        }

        if (! isset($used[$base])) {
            return $base;
        }

        $suffix = 2;

        while (isset($used[$base.'_'.$suffix])) {
            $suffix++;
        }

        return $base.'_'.$suffix;
    }

    /**
     * @param  Closure(string): bool  $taken
     */
    public function unique(string $base, Closure $taken): string
    {
        $candidate = $base;
        $suffix = 2;

        while ($taken($candidate)) {
            $candidate = $base.'_'.$suffix++;
        }

        return $candidate;
    }
}
