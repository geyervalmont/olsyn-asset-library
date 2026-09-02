<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Activate the user's current tenant for the request.
 *
 * Membership is optional at the account level, so this middleware only
 * guards routes that genuinely need a tenant. A stale or unauthorized current
 * tenant is replaced by the first membership; a user with no membership is
 * sent back to the dashboard to pick or request a workspace.
 */
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

        $tenant = $user->resolveCurrentTenant();

        if ($tenant === null) {
            if ($request->expectsJson()) {
                throw new AuthorizationException('No valid current tenant is selected.');
            }

            return redirect()
                ->route('dashboard')
                ->with('status', __('Choose a workspace to continue.'));
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
