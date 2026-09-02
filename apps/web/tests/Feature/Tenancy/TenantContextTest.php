<?php

use App\Actions\Tenants\SwitchCurrentTenant;
use App\Http\Middleware\UseCurrentTenant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Spatie\Multitenancy\Http\Middleware\EnsureValidTenantSession;
use Spatie\Multitenancy\Http\Middleware\NeedsTenant;

afterEach(function () {
    Tenant::forgetCurrent();
});

test('tenant middleware activates a users current tenant and permission team', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->withTenant($tenant)->create();

    expect($tenant->uuid)->not->toBeEmpty();

    Route::middleware(['web', 'auth', 'tenant'])->get('/_test/current-tenant', function () {
        return response()->json([
            'tenant_id' => Tenant::current()?->getKey(),
            'permission_team_id' => getPermissionsTeamId(),
        ]);
    });

    $this->actingAs($user)
        ->get('/_test/current-tenant')
        ->assertOk()
        ->assertJson([
            'tenant_id' => $tenant->getKey(),
            'permission_team_id' => $tenant->getKey(),
        ]);

    expect(Tenant::current())->toBeNull()
        ->and(getPermissionsTeamId())->toBeNull();
});

test('tenant middleware clears a current tenant without membership', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->forceFill(['current_tenant_id' => $tenant->getKey()])->save();

    Route::middleware(['web', 'auth', 'tenant'])->get('/_test/restricted-tenant', fn () => 'ok');

    $this->actingAs($user)
        ->get('/_test/restricted-tenant')
        ->assertRedirect(route('dashboard'));

    expect($user->fresh()->current_tenant_id)->toBeNull()
        ->and(Tenant::current())->toBeNull();
});

test('current tenant can only be switched to a membership', function () {
    $firstTenant = Tenant::factory()->create();
    $secondTenant = Tenant::factory()->create();
    $outsiderTenant = Tenant::factory()->create();
    $user = User::factory()->withTenant($firstTenant)->create();
    $user->tenants()->attach($secondTenant);
    $request = Request::create('/tenants/switch', 'POST');
    $session = new Store('test', new ArraySessionHandler(120));
    $session->put(SwitchCurrentTenant::TENANT_SESSION_KEY, $firstTenant->getKey());
    $request->setLaravelSession($session);

    app(SwitchCurrentTenant::class)->handle($user, $secondTenant, $request);

    expect($user->fresh()->current_tenant_id)->toBe($secondTenant->getKey())
        ->and(Tenant::current()?->is($secondTenant))->toBeTrue()
        ->and($session->has(SwitchCurrentTenant::TENANT_SESSION_KEY))->toBeFalse()
        ->and(fn () => app(SwitchCurrentTenant::class)->handle($user, $outsiderTenant, $request))
        ->toThrow(AuthorizationException::class);
});

test('tenant middleware is persisted for livewire requests', function () {
    expect(Livewire::getPersistentMiddleware())->toContain(
        UseCurrentTenant::class,
        NeedsTenant::class,
        EnsureValidTenantSession::class,
    );
});
