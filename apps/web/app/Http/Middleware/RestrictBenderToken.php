<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictBenderToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()->currentAccessToken();
        if ($token->can('bender:materials') && ! $token->can('*')) {
            abort_unless($request->is('api/v1/bender/*'), 403, 'This token is restricted to the Bender material bridge.');
        }

        return $next($request);
    }
}
