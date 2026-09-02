<?php

namespace App\Models;

use App\Library\CodeTokenizer;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $code
 * @property string|null $website
 * @property string|null $notes
 */
#[Fillable(['name', 'slug', 'code', 'website', 'notes'])]
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Supplier $supplier): void {
            $supplier->slug = $supplier->slug ?: Str::slug($supplier->name);
            $supplier->code = CodeTokenizer::token($supplier->code ?: $supplier->name, 12);
        });
    }

    /**
     * @return HasMany<Material, $this>
     */
    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }

    /**
     * @return HasMany<Source, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }
}
