<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;

class SwitchPermissionTeamTask implements SwitchTenantTask
{
    public function makeCurrent(IsTenant $tenant): void
    {
        if (! $tenant instanceof Model) {
            throw new LogicException('The permission team tenant must be an Eloquent model.');
        }

        setPermissionsTeamId($tenant);
    }

    public function forgetCurrent(): void
    {
        setPermissionsTeamId(null);
    }
}
