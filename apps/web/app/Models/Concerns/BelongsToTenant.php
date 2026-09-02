<?php

namespace App\Models\Concerns;

use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            $tenant = Tenant::current();

            if ($tenant === null) {
                throw new LogicException(sprintf(
                    'Cannot create tenant-scoped model [%s] without a current tenant.',
                    $model::class,
                ));
            }

            $tenantId = $model->getAttribute('tenant_id');

            if ($tenantId !== null && (string) $tenantId !== (string) $tenant->getKey()) {
                throw new LogicException(sprintf(
                    'Cannot create tenant-scoped model [%s] for a tenant other than the current tenant.',
                    $model::class,
                ));
            }

            $model->setAttribute('tenant_id', $tenant->getKey());
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
