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

        return $this->unique($base, fn (string $candidate): bool => $this->materialCodeTaken($candidate, $material));
    }

    /**
     * A token unique among the variant's siblings whose resulting code is
     * also free, so a variant code is always its material code plus token.
     */
    public function variantToken(Variant $variant): string
    {
        $materialCode = $variant->material->code;

        return $this->unique($variant->token, function (string $candidate) use ($variant, $materialCode): bool {
            return $this->variantTokenTaken($candidate, $variant)
                || $this->variantCodeTaken($materialCode.self::SEPARATOR.$candidate, $variant);
        });
    }

    public function variantCode(Variant $variant): string
    {
        return $variant->material->code.self::SEPARATOR.$variant->token;
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

    private function materialCodeTaken(string $code, Material $material): bool
    {
        return Material::query()->where('code', $code)->whereKeyNot($material->getKey())->exists()
            || Alias::query()->where('code', $code)->exists();
    }

    private function variantTokenTaken(string $token, Variant $variant): bool
    {
        return Variant::query()
            ->where('material_id', $variant->material_id)
            ->where('token', $token)
            ->whereKeyNot($variant->getKey())
            ->exists();
    }

    private function variantCodeTaken(string $code, Variant $variant): bool
    {
        return Variant::query()->where('code', $code)->whereKeyNot($variant->getKey())->exists()
            || Alias::query()->where('code', $code)->exists();
    }
}
