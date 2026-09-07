<?php

use App\Actions\Authorization\SyncRolesAndPermissions;
use App\Enums\Permission;
use App\Models\User;
use App\Services\OlsynOidc;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    config(['olsyn_access.enabled' => true, 'olsyn_access.token' => str_repeat('p', 48),
        'olsyn_access.client_id' => 'opal-client', 'olsyn_access.issuer' => 'https://identity.example.test',
        'olsyn_access.decision_url' => 'https://policy.example.test/decision']);
    Http::preventStrayRequests();
});

function policyResponse(bool $allowed = true, array $permissions = ['materials.view']): array
{
    return ['service' => 'opal', 'allowed' => $allowed, 'permissions' => $permissions, 'max_age' => 15];
}

it('keeps password recovery available and requires central access for local sessions', function () {
    $user = User::factory()->create();
    Http::fake(['policy.example.test/*' => Http::response(policyResponse(false, []))]);
    $this->actingAs($user)->get('/dashboard')->assertForbidden();
    $this->post('/logout')->assertRedirect();
    $this->get('/login')->assertOk()->assertSee('Continue with Olsyn');
});

it('revokes existing API tokens and browser sessions', function () {
    $user = User::factory()->create();
    $user->forceFill(['workos_id' => 'user_opal'])->save();
    Http::fake(['policy.example.test/*' => Http::sequence()->push(policyResponse())->push(policyResponse(false, []))]);
    $token = $user->createToken('integration')->plainTextToken;
    $this->withToken($token)->getJson('/api/v1/me')->assertOk();
    $this->travel(16)->seconds();
    $this->withToken($token)->getJson('/api/v1/me')->assertForbidden();
    $this->actingAs($user)->get('/dashboard')->assertForbidden();
});

it('cannot bypass central permission ceilings with a local super admin flag', function () {
    $user = User::factory()->create();
    $user->forceFill(['is_super_admin' => true])->save();
    Http::fake(['policy.example.test/*' => Http::response(policyResponse())]);
    expect(Gate::forUser($user)->allows(Permission::ContributeMaterials->value))->toBeFalse();
    expect(Gate::forUser($user)->allows(Permission::ViewMaterials->value))->toBeTrue();
});

it('does not grant local workspace permissions from central grants alone', function () {
    $user = User::factory()->create();
    app(SyncRolesAndPermissions::class)->handle();
    Http::fake(['policy.example.test/*' => Http::response(policyResponse(true, ['materials.view', 'materials.publish']))]);
    expect($user->can(Permission::PublishMaterials->value))->toBeFalse();
});

it('fails closed during policy outages and for responses intended for another service', function () {
    $user = User::factory()->create();
    Http::fake(['policy.example.test/*' => Http::response(['service' => 'render-farm', 'allowed' => true, 'permissions' => ['materials.view']])]);
    $this->actingAs($user)->get('/dashboard')->assertStatus(503);
    Http::fake(['policy.example.test/*' => Http::response([], 503)]);
    $this->get('/dashboard')->assertStatus(503);
});

it('starts an OIDC flow with a fixed callback PKCE and unpredictable state', function () {
    Http::fake(['identity.example.test/.well-known/openid-configuration' => Http::response([
        'issuer' => 'https://identity.example.test', 'authorization_endpoint' => 'https://identity.example.test/authorize',
        'token_endpoint' => 'https://identity.example.test/token', 'jwks_uri' => 'https://identity.example.test/keys',
    ])]);
    $response = $this->get('/auth/login?redirect_uri=https://evil.example.test');
    $response->assertRedirect();
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['code_challenge_method'])->toBe('S256')
        ->and($query['redirect_uri'])->toBe('https://opal.olsyn.com/auth/callback')
        ->and(strlen($query['state']))->toBe(64)->and(strlen($query['nonce']))->toBe(64);
});

it('rejects missing mismatched expired and replayed callback state without exchanging tokens', function () {
    $this->get('/auth/callback?code=code&state=wrong')->assertUnauthorized();
    $flow = ['state' => 'expected', 'nonce' => 'nonce', 'verifier' => 'verifier', 'created_at' => time()];
    $this->withSession(['olsyn.oidc' => $flow])->get('/auth/callback?code=code&state=wrong')->assertUnauthorized();
    $this->get('/auth/callback?code=code&state=expected')->assertUnauthorized();
    $flow['created_at'] = time() - 700;
    $this->withSession(['olsyn.oidc' => $flow])->get('/auth/callback?code=code&state=expected')->assertUnauthorized();
    Http::assertNothingSent();
});

it('links only prebound identities and never upgrades an existing account by email', function () {
    $user = User::factory()->create(['email' => 'owner@example.test']);
    $claims = ['sub' => 'workos-owner', 'email' => $user->email, 'name' => 'Owner', 'exp' => time() + 3600];
    $mock = Mockery::mock(OlsynOidc::class);
    $mock->shouldReceive('exchange')->andReturn($claims);
    app()->instance(OlsynOidc::class, $mock);
    Http::fake(['policy.example.test/*' => Http::response(policyResponse())]);
    $flow = ['state' => 'expected', 'nonce' => 'nonce', 'verifier' => 'verifier', 'created_at' => time()];
    $this->withSession(['olsyn.oidc' => $flow])->get('/auth/callback?code=code&state=expected')->assertForbidden();
    expect($user->fresh()->workos_id)->toBeNull();
    $user->forceFill(['workos_id' => 'workos-owner'])->save();
    $this->withSession(['olsyn.oidc' => $flow])->get('/auth/callback?code=code&state=expected')->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);
});

it('validates signed ID token issuer audience expiry and nonce', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private);
    $details = openssl_pkey_get_details($key);
    $b64 = fn ($v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
    $jwk = ['kty' => 'RSA', 'kid' => 'test', 'alg' => 'RS256', 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])];
    $base = ['iss' => 'https://identity.example.test', 'aud' => 'opal-client', 'sub' => 'user_test',
        'iat' => time(), 'exp' => time() + 300, 'nonce' => 'expected', 'email' => 'user@example.test', 'email_verified' => true];
    $flow = ['verifier' => Str::random(96), 'nonce' => 'expected'];
    foreach ([[], ['iss' => 'https://evil.example.test'], ['aud' => 'render-client'], ['exp' => time() - 10],
        ['nonce' => 'wrong'], ['email_verified' => false], ['azp' => 'other-client']] as $override) {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'identity.example.test/.well-known/openid-configuration' => Http::response([
                'issuer' => $base['iss'], 'authorization_endpoint' => $base['iss'].'/authorize',
                'token_endpoint' => $base['iss'].'/token', 'jwks_uri' => $base['iss'].'/keys']),
            'identity.example.test/keys' => Http::response(['keys' => [$jwk]]),
            'identity.example.test/token' => Http::response(['id_token' => JWT::encode(array_merge($base, $override), $private, 'RS256', 'test')]),
        ]);
        if ($override === []) {
            expect(app(OlsynOidc::class)->exchange('code', $flow)['sub'])->toBe('user_test');
        } else {
            expect(fn () => app(OlsynOidc::class)->exchange('code', $flow))->toThrow(Exception::class);
        }
    }
});
