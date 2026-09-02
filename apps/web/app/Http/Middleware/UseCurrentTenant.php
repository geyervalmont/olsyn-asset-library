<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UseCurrentTenant
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $tenant = $user->currentTenant;

        if ($tenant === null || ! $user->tenants()->whereKey($tenant->getKey())->exists()) {
            throw new AuthorizationException('No valid current tenant is selected.');
        }

        $tenant->makeCurrent();
        $user->unsetRelation('roles')->unsetRelation('permissions');

        try {
            return $next($request);
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
