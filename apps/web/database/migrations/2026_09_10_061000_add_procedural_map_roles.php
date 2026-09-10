<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            ['slug' => 'hatch_svg', 'name' => 'Vector hatch (SVG)', 'sort_order' => 15],
            ['slug' => 'hatch_pat', 'name' => 'Revit hatch (PAT)', 'sort_order' => 16],
        ] as $role) {
            DB::table('map_roles')->updateOrInsert(
                ['slug' => $role['slug']],
                [...$role, 'colour_space' => null, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        $inUse = DB::table('representation_files')
            ->join('map_roles', 'map_roles.id', '=', 'representation_files.map_role_id')
            ->whereIn('map_roles.slug', ['hatch_svg', 'hatch_pat'])
            ->exists();

        if (! $inUse) {
            DB::table('map_roles')->whereIn('slug', ['hatch_svg', 'hatch_pat'])->delete();
        }
    }
};
