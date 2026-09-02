<?php

namespace Database\Factories;

use App\Actions\Tenants\AddTenantMember;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'is_super_admin' => false,
        ];
    }

    /**
     * Indicate that the user bypasses all authorization.
     */
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_super_admin' => true,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Attach the user to a tenant and make it their current tenant.
     *
     * A role is only assigned when requested, because role definitions must
     * already be synced for the assignment to resolve.
     */
    public function withTenant(?Tenant $tenant = null, ?Role $role = null): static
    {
        return $this->afterCreating(function (User $user) use ($tenant, $role): void {
            $currentTenant = $tenant ?? Tenant::factory()->create();

            if ($role !== null) {
                app(AddTenantMember::class)->handle($currentTenant, $user, $role);
            } else {
                $user->tenants()->attach($currentTenant);
            }

            $user->forceFill(['current_tenant_id' => $currentTenant->getKey()])->save();
        });
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
