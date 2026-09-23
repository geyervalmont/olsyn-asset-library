<?php

namespace App\Support;

use App\Models\Tenant;

final class SharedWorkspace
{
    public function tenant(): ?Tenant
    {
        $slug = config('opal.workspace.slug');
        if (is_string($slug) && $slug !== '') {
            return Tenant::query()->where('slug', $slug)->first();
        }

        // Only infer a workspace when it is unambiguous. Never select an
        // arbitrary first workspace in an installation with multiple tenants.
        $tenants = Tenant::query()->limit(2)->get();

        return $tenants->count() === 1 ? $tenants->first() : null;
    }
}
