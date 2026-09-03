<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property bool $is_super_admin
 * @property int|null $current_tenant_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function currentTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'current_tenant_id');
    }

    /**
     * @return BelongsToMany<Tenant, $this>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)->withTimestamps();
    }

    /**
     * Super-admins bypass every authorization check, in every tenant.
     */
    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin;
    }

    public function isMemberOf(Tenant $tenant): bool
    {
        return $this->tenants()->whereKey($tenant->getKey())->exists();
    }

    /**
     * Whether the user may operate inside the given tenant.
     */
    public function canAccessTenant(Tenant $tenant): bool
    {
        return $this->isSuperAdmin() || $this->isMemberOf($tenant);
    }

    /**
     * Every tenant the user may enter: memberships, or all tenants for super-admins.
     *
     * @return Builder<Tenant>
     */
    public function accessibleTenants(): Builder
    {
        $query = Tenant::query()->orderBy('name');

        if ($this->isSuperAdmin()) {
            return $query;
        }

        return $query->whereHas('users', fn (Builder $users) => $users->whereKey($this->getKey()));
    }

    /**
     * The tenant to operate in, repairing a stale selection when possible.
     *
     * Returns null when the user has nothing to select.
     */
    public function resolveCurrentTenant(): ?Tenant
    {
        $tenant = $this->currentTenant;

        if ($tenant !== null && $this->canAccessTenant($tenant)) {
            return $tenant;
        }

        $tenant = $this->tenants()->orderBy('name')->first();

        if ($tenant === null) {
            if ($this->current_tenant_id !== null) {
                $this->forceFill(['current_tenant_id' => null])->save();
            }

            return null;
        }

        $this->forceFill(['current_tenant_id' => $tenant->getKey()])->save();
        $this->setRelation('currentTenant', $tenant);

        return $tenant;
    }

    /**
     * The user's role inside a tenant, if they are a member.
     */
    public function roleIn(Tenant $tenant): ?Role
    {
        if (! $this->isMemberOf($tenant)) {
            return null;
        }

        return $tenant->execute(function (): ?Role {
            $this->unsetRelation('roles');

            foreach (Role::cases() as $role) {
                if ($this->hasRole($role->value)) {
                    return $role;
                }
            }

            return null;
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
