<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One typed value on a variant: (colourway, "Ashen", supplier code 634014001).
 * A value may reference another variant, which is how a furniture part will
 * later point at a material option.
 *
 * @property int $id
 * @property int $variant_id
 * @property int $variant_type_id
 * @property string $value
 * @property string|null $supplier_code
 * @property string|null $supplier_name
 * @property int|null $ref_variant_id
 * @property-read VariantType $type
 */
#[Fillable(['variant_type_id', 'value', 'supplier_code', 'supplier_name', 'ref_variant_id'])]
class VariantAttribute extends Model
{
    protected static function booted(): void
    {
        static::saved(function (VariantAttribute $attribute): void {
            $attribute->variant->refreshSearchText();
            $attribute->variant->material->refreshSearchText();
        });

        static::deleted(function (VariantAttribute $attribute): void {
            $attribute->variant->refreshSearchText();
            $attribute->variant->material->refreshSearchText();
        });
    }

    /**
     * @return BelongsTo<Variant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    /**
     * @return BelongsTo<VariantType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(VariantType::class, 'variant_type_id');
    }

    /**
     * @return BelongsTo<Variant, $this>
     */
    public function referencedVariant(): BelongsTo
    {
        return $this->belongsTo(Variant::class, 'ref_variant_id');
    }
}
