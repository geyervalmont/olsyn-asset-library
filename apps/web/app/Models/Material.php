<?php

namespace App\Models;

use App\Enums\MaterialStatus;
use App\Enums\Permission;
use App\Enums\Visibility;
use App\Library\MaterialCodes;
use App\Models\Concerns\HasAliases;
use App\Models\Concerns\HasProvenance;
use App\Models\Concerns\HasTags;
use App\Models\Concerns\Searchable;
use Database\Factories\MaterialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use LogicException;

/**
 * A material in the OPAL library. Owned by the library, never by a tenant;
 * contributions are recorded, not ownership.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $slug
 * @property int $category_id
 * @property int|null $supplier_id
 * @property int|null $source_id
 * @property string|null $collection
 * @property string|null $supplier_product_code
 * @property string|null $description
 * @property string|null $material_type
 * @property string|null $form
 * @property string|null $tile_width_mm
 * @property string|null $tile_height_mm
 * @property string|null $thickness_mm
 * @property string|null $repeat_type
 * @property string|null $install_pattern
 * @property string|null $sqm_cost
 * @property string|null $currency
 * @property string|null $lead_time
 * @property array<string, mixed>|null $specifications
 * @property MaterialStatus $status
 * @property Visibility $visibility
 * @property int|null $contributed_by_tenant_id
 * @property int|null $contributed_by_user_id
 * @property int|null $current_version_id
 * @property string $search_text
 * @property-read Category $category
 * @property-read Supplier|null $supplier
 */
#[Fillable([
    'name', 'slug', 'category_id', 'supplier_id', 'source_id', 'collection', 'supplier_product_code',
    'description', 'material_type', 'form', 'tile_width_mm', 'tile_height_mm', 'thickness_mm',
    'repeat_type', 'install_pattern', 'sqm_cost', 'currency', 'lead_time', 'specifications', 'status', 'visibility',
    'contributed_by_tenant_id', 'contributed_by_user_id',
])]
class Material extends Model
{
    /** @use HasFactory<MaterialFactory> */
    use HasAliases, HasFactory, HasProvenance, HasTags, Searchable;

    private bool $recoding = false;

    private static bool $deferSearchRefresh = false;

    /**
     * Run bulk work without re-indexing a material on every variant save;
     * every touched material is re-indexed once afterwards.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withDeferredSearchRefresh(callable $callback): mixed
    {
        $previous = self::$deferSearchRefresh;
        self::$deferSearchRefresh = true;

        try {
            return $callback();
        } finally {
            self::$deferSearchRefresh = $previous;
        }
    }

    public static function isDeferringSearchRefresh(): bool
    {
        return self::$deferSearchRefresh;
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'visibility' => 'library',
    ];

    protected static function booted(): void
    {
        static::saving(function (Material $material): void {
            $material->slug = $material->slug ?: Str::slug($material->name);

            if (! $material->exists && ($material->code ?? '') === '') {
                $material->code = app(MaterialCodes::class)->materialCode($material);
            }

            if ($material->exists && $material->isDirty('code') && ! $material->recoding) {
                throw new LogicException(sprintf(
                    'Material code [%s] is immutable; use the recode action to change it.',
                    $material->getOriginal('code'),
                ));
            }

            $material->search_text = $material->buildSearchText();
        });
    }

    /**
     * Find a material by its current code or any retired alias.
     */
    public static function resolveCode(string $code): ?self
    {
        $code = strtoupper(trim($code));

        $material = static::query()->where('code', $code)->first();

        if ($material !== null) {
            return $material;
        }

        $alias = Alias::query()
            ->where('code', $code)
            ->where('aliasable_type', Relation::getMorphAlias(static::class))
            ->first();

        return $alias === null ? null : static::query()->find($alias->aliasable_id);
    }

    /**
     * Change the code inside a recode action, keeping the previous one as an alias.
     *
     * @internal Use App\Actions\Materials\RecodeMaterial.
     */
    public function applyRecode(string $code, ?string $reason = null): void
    {
        $previous = $this->code;

        $this->recoding = true;

        try {
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
        $variants = $this->exists
            ? $this->variants()->with('attributes')->get()
            : collect();

        return $this->joinSearchParts([
            $this->code,
            $this->name,
            $this->supplier?->name,
            $this->supplier?->code,
            $this->supplier_product_code,
            $this->collection,
            $this->category->name,
            $this->material_type,
            $this->form,
            $this->description,
            $this->exists ? $this->tags()->pluck('name')->all() : [],
            $variants->map(fn (Variant $variant): array => $variant->searchParts())->all(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'specifications' => 'array',
            'status' => MaterialStatus::class,
            'visibility' => Visibility::class,
        ];
    }

    /**
     * Materials a user may see: everything for super-admins and for users who
     * publish (they administer the library), otherwise the library-wide ones
     * plus those granted to them or to one of their tenants.
     *
     * @param  Builder<Material>  $query
     * @return Builder<Material>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin() || $user->can(Permission::PublishMaterials->value)) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($user): void {
            $query->where('visibility', Visibility::Library->value)
                ->orWhereHas('grants', function (Builder $grants) use ($user): void {
                    $grants->where(function (Builder $grants) use ($user): void {
                        $grants->where('grantee_type', $user->getMorphClass())->where('grantee_id', $user->getKey());
                    })->orWhere(function (Builder $grants) use ($user): void {
                        $grants->where('grantee_type', (new Tenant)->getMorphClass())
                            ->whereIn('grantee_id', $user->tenants()->select('tenants.id'));
                    });
                });
        });
    }

    /**
     * @param  Builder<Material>  $query
     * @return Builder<Material>
     */
    public function scopeVisibleToDrive(Builder $query, Drive $drive): Builder
    {
        return $query->where(function (Builder $query) use ($drive): void {
            $query->where('visibility', Visibility::Library->value)
                ->orWhereHas('grants', function (Builder $grants) use ($drive): void {
                    $grants->where(function (Builder $grants) use ($drive): void {
                        $grants->where('grantee_type', $drive->getMorphClass())->where('grantee_id', $drive->getKey());
                    });

                    if ($drive->tenant_id !== null) {
                        $grants->orWhere(function (Builder $grants) use ($drive): void {
                            $grants->where('grantee_type', (new Tenant)->getMorphClass())->where('grantee_id', $drive->tenant_id);
                        });
                    }
                });
        });
    }

    public function isVisibleTo(User $user): bool
    {
        return static::query()->whereKey($this->getKey())->visibleTo($user)->exists();
    }

    /**
     * @return HasMany<MaterialGrant, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(MaterialGrant::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * @return HasMany<Variant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<MaterialVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(MaterialVersion::class)->orderBy('number');
    }

    /**
     * @return BelongsTo<MaterialVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(MaterialVersion::class, 'current_version_id');
    }

    /**
     * Every representation of every variant of this material.
     *
     * @return HasManyThrough<Representation, Variant, $this>
     */
    public function representations(): HasManyThrough
    {
        return $this->hasManyThrough(Representation::class, Variant::class);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function contributedByTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'contributed_by_tenant_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function contributedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contributed_by_user_id');
    }
}
