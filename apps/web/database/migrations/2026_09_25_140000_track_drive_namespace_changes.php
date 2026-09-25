<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Every table whose committed metadata can affect a shared projection.
     * @var list<literal-string>
     */
    private array $tables = ['materials', 'variants', 'categories', 'material_versions', 'material_version_packages',
        'packages', 'package_derivatives', 'package_derivative_files', 'files', 'material_grants',
        'targets', 'quality_tiers', 'map_roles', 'drives'];

    public function up(): void
    {
        Schema::create('drive_namespace_revision', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedBigInteger('revision');
        });
        DB::table('drive_namespace_revision')->insert(['id' => 1, 'revision' => 1]);
        if (DB::getDriverName() !== 'pgsql') {
            return; // Other databases use uncached manifests.
        }
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION opal_invalidate_drive_namespace() RETURNS trigger AS $$
            BEGIN
                UPDATE drive_namespace_revision SET revision = revision + 1 WHERE id = 1;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
            SQL);
        foreach ($this->tables as $table) {
            DB::unprepared("CREATE TRIGGER opal_drive_namespace_changed AFTER INSERT OR UPDATE OR DELETE OR TRUNCATE ON {$table} FOR EACH STATEMENT EXECUTE FUNCTION opal_invalidate_drive_namespace()");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach ($this->tables as $table) {
                DB::unprepared("DROP TRIGGER IF EXISTS opal_drive_namespace_changed ON {$table}");
            }
            DB::unprepared('DROP FUNCTION IF EXISTS opal_invalidate_drive_namespace()');
        }
        Schema::dropIfExists('drive_namespace_revision');
    }
};
