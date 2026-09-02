<?php

namespace App\Actions\Platforms;

use App\Models\Platform;
use App\Models\PlatformIdentity;
use App\Models\Variant;

class AssignPlatformIdentity
{
    /**
     * Record or update what a variant is called on a platform.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public function handle(Variant $variant, Platform|string $platform, ?string $externalId = null, ?string $externalName = null, ?array $payload = null, string $status = 'confirmed'): PlatformIdentity
    {
        $platform = $platform instanceof Platform ? $platform : Platform::fromSlug($platform);

        return PlatformIdentity::query()->updateOrCreate(
            ['variant_id' => $variant->getKey(), 'platform_id' => $platform->getKey()],
            ['external_id' => $externalId, 'external_name' => $externalName, 'payload' => $payload, 'status' => $status],
        );
    }
}
