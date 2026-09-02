<?php

namespace Database\Seeders;

use App\Actions\Authorization\SyncRolesAndPermissions;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(SyncRolesAndPermissions $sync): void
    {
        $sync->handle();
    }
}
