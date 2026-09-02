<?php

namespace App\Actions\Tenants;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateTenant
{
    public function __construct(private readonly AddTenantMember $addMember) {}

    /**
     * Create a tenant and, optionally, make a user its first admin.
     */
    public function handle(string $name, ?User $owner = null, ?string $slug = null): Tenant
    {
        return DB::transaction(function () use ($name, $owner, $slug): Tenant {
            $tenant = Tenant::create([
                'name' => $name,
                'slug' => $slug ?? $this->uniqueSlug($name),
            ]);

            if ($owner !== null) {
                $this->addMember->handle($tenant, $owner, Role::Admin);
            }

            return $tenant;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'tenant';
        $slug = $base;
        $suffix = 2;

        while (Tenant::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
