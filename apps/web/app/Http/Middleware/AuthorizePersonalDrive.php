<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthorizePersonalDrive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user?->can('materials.view') && $user->tokenCan('drive:read'), 403);
        if ($request->is('api/v1/drive/intake*')) {
            abort_unless($user->can('materials.contribute'), 403);
            if (! $request->isMethodSafe()) {
                abort_unless($user->tokenCan('drive:write'), 403);
            }
        }

        return $next($request);
    }
}
