<?php

namespace Database\Seeders;

use App\Actions\Tenants\AddTenantMember;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Idempotent development seed.
 *
 * Accounts (all with password "password"):
 *  - admin@example.com  super-admin, admin of Olsyn
 *  - test@example.com   editor in Olsyn
 *  - viewer@example.com viewer in Olsyn
 *  - solo@example.com   no workspace membership
 */
class DatabaseSeeder extends Seeder
{
    public function run(AddTenantMember $addMember): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => 'olsyn'],
            ['name' => 'Olsyn'],
        );

        $admin = $this->user('admin@example.com', 'Olsyn Admin');
        $admin->forceFill(['is_super_admin' => true])->save();
        $addMember->handle($tenant, $admin, Role::Admin);

        $addMember->handle($tenant, $this->user('test@example.com', 'Test User'), Role::Editor);
        $addMember->handle($tenant, $this->user('viewer@example.com', 'Viewer User'), Role::Viewer);

        $this->user('solo@example.com', 'Solo User');
    }

    private function user(string $email, string $name): User
    {
        return User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => 'password',
                'email_verified_at' => now(),
            ],
        );
    }
}
