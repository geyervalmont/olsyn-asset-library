<?php

namespace App\Models;

use Database\Factories\DefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A material described rather than scanned.
 *
 * A Dulux colour is a CIELAB value and a sheen; a generated tile is a generator
 * and its parameters. Baking either produces the canonical representation a
 * scanned set already arrives as, so a paint is not a special case downstream —
 * it is the same pipeline with the bake reduced to almost nothing.
 *
 * @property int $id
 * @property int $variant_id
 * @property string $generator
 * @property string|null $generator_version
 * @property array<string, mixed> $parameters
 * @property Carbon|null $baked_at
 * @property string|null $baked_digest
 * @property-read Variant $variant
 */
#[Fillable(['generator', 'generator_version', 'parameters'])]
class Definition extends Model
{
    /** @use HasFactory<DefinitionFactory> */
    use HasFactory;

    /**
     * Record that the bake ran. The digest is taken here rather than passed in,
     * so it always describes the parameters that were actually baked — a caller
     * cannot stamp a definition as current against inputs it never used.
     */
    public function markBaked(): void
    {
        $this->forceFill([
            'baked_at' => now(),
            'baked_digest' => $this->digest(),
        ])->save();
    }

    /**
     * True when the parameters have changed since the last bake, so a stale
     * result is visible rather than quietly served.
     */
    public function isStale(): bool
    {
        return $this->baked_at === null || $this->baked_digest !== $this->digest();
    }

    /**
     * A stable digest of what the bake consumed. Key order must not matter, or
     * an unchanged definition would look stale after a round trip through JSON.
     */
    public function digest(): string
    {
        $parameters = $this->parameters;
        ksort($parameters);

        return hash('sha256', $this->generator.'@'.($this->generator_version ?? '').':'.json_encode($parameters));
    }

    /**
     * @return BelongsTo<Variant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'baked_at' => 'datetime',
        ];
    }
}
