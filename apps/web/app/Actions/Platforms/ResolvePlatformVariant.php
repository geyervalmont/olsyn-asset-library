<?php

namespace App\Actions\Platforms;

use App\Models\Platform;
use App\Models\PlatformIdentity;
use App\Models\Variant;

/**
 * Find the library variant behind a platform reference: an external id, an
 * external name, or any string carrying a variant code such as
 * "Academix Ashen [CPT-TARKETT-ACADEMIX-ASHEN]".
 */
class ResolvePlatformVariant
{
    public function handle(Platform|string $platform, string $reference): ?Variant
    {
        $platform = $platform instanceof Platform ? $platform : Platform::fromSlug($platform);
        $reference = trim($reference);

        if ($reference === '') {
            return null;
        }

        $identity = PlatformIdentity::query()
            ->where('platform_id', $platform->getKey())
            ->where(fn ($query) => $query->where('external_id', $reference)->orWhere('external_name', $reference))
            ->orderByRaw('CASE WHEN external_id = ? THEN 0 ELSE 1 END', [$reference])
            ->first();

        if ($identity !== null) {
            return $identity->variant;
        }

        return $this->fromEmbeddedCode($reference);
    }

    public function fromEmbeddedCode(string $reference): ?Variant
    {
        preg_match_all('/[A-Z0-9_]+(?:-[A-Z0-9_]+){3,}/i', $reference, $matches);

        foreach ($matches[0] as $candidate) {
            $variant = Variant::resolveCode($candidate);

            if ($variant !== null) {
                return $variant;
            }
        }

        return null;
    }
}
