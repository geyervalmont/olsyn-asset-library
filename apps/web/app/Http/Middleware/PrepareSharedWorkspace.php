<?php

namespace App\Http\Middleware;

use App\Actions\Workspace\JoinSharedWorkspace;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PrepareSharedWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! config('opal.workspace.single') || $user === null || ! $user->hasVerifiedEmail()
            || $request->is('logout', 'login', 'auth/*', 'forgot-password', 'reset-password*', 'email/*', 'two-factor-challenge')) {
            return $next($request);
        }
        $tenant = app(JoinSharedWorkspace::class)->handle($user);
        if ($tenant === null) {
            Tenant::forgetCurrent();
        } else {
            $tenant->makeCurrent();
        }
        $user->unsetRelation('roles')->unsetRelation('permissions');
        try {
            return $next($request);
        } finally {
            if ($request->hasHeader('X-Livewire')) {
                app()->terminating(static fn () => Tenant::forgetCurrent());
            } else {
                Tenant::forgetCurrent();
            }
        }
    }
}
