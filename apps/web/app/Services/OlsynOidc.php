<?php

namespace App\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** OIDC authorization-code client: separate credentials, PKCE, state and nonce. */
class OlsynOidc
{
    /** @return array<string, mixed> */
    public function metadata(): array
    {
        $issuer = rtrim(config('olsyn_access.issuer'), '/');
        abort_unless(str_starts_with($issuer, 'https://'), 503);
        $metadata = Cache::remember('olsyn-oidc:metadata:'.hash('sha256', $issuer), 3600,
            fn () => Http::timeout(5)->get($issuer.'/.well-known/openid-configuration')->throw()->json());
        abort_unless(($metadata['issuer'] ?? null) === $issuer, 503);
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $endpoint) {
            abort_unless(str_starts_with($metadata[$endpoint] ?? '', 'https://'), 503);
        }

        return $metadata;
    }

    /**
     * @param  array{verifier: string, nonce: string}  $flow
     * @return array<string, mixed>
     */
    public function exchange(string $code, array $flow): array
    {
        $metadata = $this->metadata();
        $tokens = Http::asForm()->timeout(10)->post($metadata['token_endpoint'], [
            'grant_type' => 'authorization_code', 'code' => $code,
            'client_id' => config('olsyn_access.client_id'),
            'redirect_uri' => config('olsyn_access.redirect_uri'), 'code_verifier' => $flow['verifier'],
        ])->throw()->json();
        // Fetch fresh keys at login so key rotation cannot strand users.
        $keys = Http::timeout(5)->get($metadata['jwks_uri'])->throw()->json();
        $claims = (array) JWT::decode($tokens['id_token'], JWK::parseKeySet($keys, 'RS256'));
        $audiences = (array) ($claims['aud'] ?? []);
        abort_unless(($claims['iss'] ?? null) === $metadata['issuer']
            && in_array(config('olsyn_access.client_id'), $audiences, true)
            && (count($audiences) === 1 || ($claims['azp'] ?? null) === config('olsyn_access.client_id'))
            && (! isset($claims['azp']) || $claims['azp'] === config('olsyn_access.client_id'))
            && is_numeric($claims['exp'] ?? null) && $claims['exp'] > time()
            && is_numeric($claims['iat'] ?? null) && $claims['iat'] <= time() + 60
            && is_string($claims['sub'] ?? null) && $claims['sub'] !== ''
            && is_string($claims['nonce'] ?? null) && hash_equals($flow['nonce'], $claims['nonce']), 401);
        abort_unless(($claims['email_verified'] ?? false) === true
            && filter_var($claims['email'] ?? '', FILTER_VALIDATE_EMAIL), 401, 'A verified email is required.');

        return $claims;
    }
}
