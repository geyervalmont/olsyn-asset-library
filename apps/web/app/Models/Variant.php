<?php

namespace App\Models;

use App\Library\CodeTokenizer;
use App\Library\MaterialCodes;
use App\Models\Concerns\HasAliases;
use App\Models\Concerns\HasProvenance;
use App\Models\Concerns\Searchable;
use Database\Factories\VariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use LogicException;

/**
 * The consumable unit of a material: one colourway, saturation, finish,
 * pattern or format combination, described by typed attributes.
 *
 * @property int $id
 * @property int $material_id
 * @property string $code
 * @property string $token
 * @property string $name
 * @property int $position
 * @property string|null $tile_width_mm
 * @property string|null $tile_height_mm
 * @property string|null $thickness_mm
 * @property string|null $repeat_type
 * @property string|null $install_pattern
 * @property string|null $dominant_hex
 * @property string|null $colour_family
 * @property string|null $colour_l
 * @property string|null $colour_a
 * @property string|null $colour_b
 * @property string $search_text
 * @property-read Material $material
 */
#[Fillable([
    'name', 'token', 'position', 'tile_width_mm', 'tile_height_mm', 'thickness_mm', 'repeat_type',
    'install_pattern', 'dominant_hex', 'colour_family', 'colour_l', 'colour_a', 'colour_b',
])]
class Variant extends Model
{
    /** @use HasFactory<VariantFactory> */
    use HasAliases, HasFactory, HasProvenance, Searchable;

    private bool $recoding = false;

    protected static function booted(): void
    {
        static::saving(function (Variant $variant): void {
            $variant->token = CodeTokenizer::token($variant->token ?: $variant->name);

            if (! $variant->exists && ($variant->code ?? '') === '') {
                $codes = app(MaterialCodes::class);
                $variant->token = $codes->variantToken($variant);
                $variant->code = $codes->variantCode($variant);
            }

            if ($variant->exists && $variant->isDirty('code') && ! $variant->recoding) {
                throw new LogicException(sprintf(
                    'Variant code [%s] is immutable; use the recode action to change it.',
                    $variant->getOriginal('code'),
                ));
            }

            $variant->search_text = $variant->buildSearchText();
        });

        static::saved(function (Variant $variant): void {
            if (! Material::isDeferringSearchRefresh()) {
                $variant->material->refreshSearchText();
            }
        });

        static::deleted(function (Variant $variant): void {
            $variant->material->refreshSearchText();
        });
    }

    /**
     * Find a variant by its current code or any retired alias.
     */
    public static function resolveCode(string $code): ?self
    {
        $code = strtoupper(trim($code));

        $variant = static::query()->where('code', $code)->first();

        if ($variant !== null) {
            return $variant;
        }

        $alias = Alias::query()
            ->where('code', $code)
            ->where('aliasable_type', Relation::getMorphAlias(static::class))
            ->first();

        return $alias === null ? null : static::query()->find($alias->aliasable_id);
    }

    /**
     * @return HasMany<PlatformIdentity, $this>
     */
    public function platformIdentities(): HasMany
    {
        return $this->hasMany(PlatformIdentity::class);
    }

    /**
     * @internal Use App\Actions\Materials\RecodeMaterial.
     */
    public function applyRecode(string $code, ?string $reason = null): void
    {
        $previous = $this->code;

        $this->recoding = true;

        try {
            $this->token = (string) substr($code, strlen($this->material->code) + 1);
            $this->code = $code;
            $this->save();
        } finally {
            $this->recoding = false;
        }

        if ($previous !== $code) {
            $this->addAlias($previous, $reason);
        }
    }

    public function buildSearchText(): string
    {
        return $this->joinSearchParts([
            $this->code,
            $this->material->name,
            $this->material->supplier?->name,
            $this->material->supplier_product_code,
            $this->searchParts(),
        ]);
    }

    /**
     * The variant-specific words that also feed the parent material's index.
     *
     * @return array<int, string>
     */
    public function searchParts(): array
    {
        $attributes = $this->exists
            ? $this->attributes()->get()
            : collect();

        return collect([$this->name, $this->colour_family])
            ->merge($attributes->flatMap(fn (VariantAttribute $attribute): array => [
                $attribute->value,
                $attribute->supplier_code,
                $attribute->supplier_name,
            ]))
            ->filter(fn (mixed $part): bool => is_string($part) && $part !== '')
            ->values()
            ->all();
    }

    public function effectiveTileWidthMm(): ?string
    {
        return $this->tile_width_mm ?? $this->material->tile_width_mm;
    }

    public function effectiveTileHeightMm(): ?string
    {
        return $this->tile_height_mm ?? $this->material->tile_height_mm;
    }

    public function attributeValue(string $typeSlug): ?string
    {
        $type = VariantType::findBySlug($typeSlug);

        if ($type === null) {
            return null;
        }

        return $this->attributes()->where('variant_type_id', $type->getKey())->value('value');
    }

    /**
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * @return HasMany<Representation, $this>
     */
    public function representations(): HasMany
    {
        return $this->hasMany(Representation::class);
    }

    /**
     * @return HasMany<VariantAttribute, $this>
     */
    public function attributes(): HasMany
    {
        return $this->hasMany(VariantAttribute::class);
    }
}
