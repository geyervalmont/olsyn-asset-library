<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $variant_id
 * @property int $platform_id
 * @property string|null $external_id
 * @property string|null $external_name
 * @property array<string, mixed>|null $payload
 * @property string $status
 * @property-read Variant $variant
 * @property-read Platform $platform
 */
#[Fillable(['variant_id', 'platform_id', 'external_id', 'external_name', 'payload', 'status'])]
class PlatformIdentity extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    /**
     * @return BelongsTo<Variant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    /**
     * @return BelongsTo<Platform, $this>
     */
    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }
}
