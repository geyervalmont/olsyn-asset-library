<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\OlsynAccess;
use App\Services\OlsynOidc;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

class OlsynLoginController extends Controller
{
    public function login(Request $request, OlsynOidc $oidc): RedirectResponse
    {
        abort_unless(config('olsyn_access.enabled'), 404);
        abort_unless(config('olsyn_access.client_id'), 503);
        $flow = ['state' => Str::random(64), 'nonce' => Str::random(64), 'verifier' => Str::random(96), 'created_at' => time()];
        $request->session()->put('olsyn.oidc', $flow);
        $query = ['client_id' => config('olsyn_access.client_id'), 'redirect_uri' => config('olsyn_access.redirect_uri'),
            'response_type' => 'code', 'scope' => 'openid profile email', 'state' => $flow['state'], 'nonce' => $flow['nonce'],
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $flow['verifier'], true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256'];

        return redirect()->away($oidc->metadata()['authorization_endpoint'].'?'.http_build_query($query));
    }

    public function callback(Request $request, OlsynOidc $oidc, OlsynAccess $access): RedirectResponse
    {
        abort_unless(config('olsyn_access.enabled'), 404);
        $flow = $request->session()->pull('olsyn.oidc');
        abort_unless(is_array($flow)
            && is_int($flow['created_at'] ?? null) && time() - $flow['created_at'] <= 600
            && is_string($flow['state'] ?? null) && is_string($flow['nonce'] ?? null) && is_string($flow['verifier'] ?? null)
            && is_string($request->query('state')) && hash_equals($flow['state'], $request->query('state'))
            && is_string($request->query('code')) && ! $request->has('error'), 401, 'Login expired. Please start again.');
        try {
            $claims = $oidc->exchange($request->query('code'), $flow);
        } catch (Throwable) {
            abort(401, 'Login could not be verified. Please start again.');
        }
        $decision = $access->decision(['workos_id' => $claims['sub']]);
        abort_unless($decision['allowed'] && in_array('materials.view', $decision['permissions'], true), 403, 'Ask an Olsyn administrator for Opal access.');
        $user = User::where('workos_id', $claims['sub'])->first();
        if (! $user) {
            // Never attach a new external identity to an existing privileged account by email alone.
            abort_if(User::whereRaw('lower(email) = ?', [strtolower($claims['email'])])->exists(), 403,
                'Your existing Opal account needs to be linked by an administrator. You can still use your existing login.');
            $user = new User;
            $user->forceFill(['name' => $claims['name'] ?? $decision['name'] ?? $claims['email'],
                'email' => strtolower($claims['email']), 'email_verified_at' => now(), 'workos_id' => $claims['sub'],
                'password' => Str::random(96)])->save();
        }
        Auth::login($user);
        $request->session()->regenerate();
        // Absolute lifetime; activity does not prolong the WorkOS-authenticated session.
        $request->session()->put('olsyn.login_expires_at', min((int) $claims['exp'], time() + 28800));

        return redirect()->route('dashboard');
    }
}
