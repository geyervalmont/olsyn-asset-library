<?php

namespace App\Models;

use Database\Factories\DriveFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A projected namespace: what a PrismFS mount or SMB share can see.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $root_path
 * @property int|null $tenant_id
 * @property int|null $target_id
 * @property string|null $description
 * @property bool $is_active
 * @property string|null $access_token_hash
 * @property Carbon|null $token_issued_at
 * @property-read Tenant|null $tenant
 * @property-read Target|null $target
 */
#[Fillable(['name', 'slug', 'root_path', 'tenant_id', 'target_id', 'description', 'is_active'])]
class Drive extends Model
{
    /** @use HasFactory<DriveFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Drive $drive): void {
            $drive->slug = Str::slug($drive->slug ?: $drive->name);
            $drive->root_path = '/'.trim($drive->root_path ?: 'materials', '/');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'token_issued_at' => 'datetime'];
    }

    /**
     * Issue a fresh bearer token for PrismFS. The plaintext is returned once
     * and never stored; issuing again invalidates the previous token.
     */
    public function issueToken(): string
    {
        $token = 'opal_'.Str::random(48);

        $this->forceFill([
            'access_token_hash' => hash('sha256', $token),
            'token_issued_at' => now(),
        ])->save();

        return $token;
    }

    public function hasToken(): bool
    {
        return $this->access_token_hash !== null;
    }

    public function tokenMatches(?string $token): bool
    {
        return $token !== null
            && $this->access_token_hash !== null
            && hash_equals($this->access_token_hash, hash('sha256', $token));
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Target, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }

    /**
     * @return MorphMany<MaterialGrant, $this>
     */
    public function grants(): MorphMany
    {
        return $this->morphMany(MaterialGrant::class, 'grantee');
    }
}
