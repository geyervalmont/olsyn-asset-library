<?php

namespace App\Http\Middleware;

use App\Services\OlsynAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOlsynAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('olsyn_access.enabled') && $user = $request->user()) {
            // Logout and the login/recovery screens must remain reachable after revocation.
            if ($request->is('logout', 'login', 'auth/*', 'forgot-password', 'reset-password*', 'two-factor-challenge')) {
                return $next($request);
            }
            if ($request->hasSession() && $expires = $request->session()->get('olsyn.login_expires_at')) {
                if ($expires <= time()) {
                    auth()->logout();
                    $request->session()->invalidate();
                    abort(401, 'Please sign in again.');
                }
            }
            abort_unless(app(OlsynAccess::class)->allows($user, 'materials.view'), 403, 'Your Opal access has been removed.');
        }

        return $next($request);
    }
}
