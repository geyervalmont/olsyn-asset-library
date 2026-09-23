<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // A database default also covers old application pods during rollout and
        // bulk writers. Works on PostgreSQL versions without native uuidv7().
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION opal_uuid_v7() RETURNS uuid LANGUAGE sql VOLATILE AS $$
                    SELECT (lpad(to_hex(floor(extract(epoch FROM clock_timestamp()) * 1000)::bigint), 12, '0')
                        || '7' || substr(value, 14, 3) || substr(value, 17, 16))::uuid
                    FROM (SELECT replace(gen_random_uuid()::text, '-', '') AS value) random;
                $$;
                CREATE OR REPLACE FUNCTION opal_keep_uuid() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    IF NEW.uuid IS DISTINCT FROM OLD.uuid THEN
                        RAISE EXCEPTION 'OPAL UUIDs are immutable';
                    END IF;
                    RETURN NEW;
                END;
                $$;
                SQL);
        }

        foreach (['materials', 'variants', 'package_derivatives'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->uuid('uuid')->nullable()->unique();
            });
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE {$name} ALTER COLUMN uuid SET DEFAULT opal_uuid_v7()");
                DB::statement("UPDATE {$name} SET uuid = opal_uuid_v7() WHERE uuid IS NULL");
                DB::statement("ALTER TABLE {$name} ALTER COLUMN uuid SET NOT NULL");
                DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_uuid_v7 CHECK (substring(uuid::text, 15, 1) = '7' AND substring(uuid::text, 20, 1) IN ('8','9','a','b'))");
                DB::statement("CREATE TRIGGER {$name}_keep_uuid BEFORE UPDATE OF uuid ON {$name} FOR EACH ROW EXECUTE FUNCTION opal_keep_uuid()");
            } else {
                DB::table($name)->orderBy('id')->chunkById(500, function ($rows) use ($name): void {
                    foreach ($rows as $row) {
                        DB::table($name)->where('id', $row->id)->update(['uuid' => (string) Str::uuid7()]);
                    }
                });
            }
        }

        Schema::table('drives', function (Blueprint $table): void {
            // Existing mounts retain their paths; new drives opt into stable paths.
            $table->string('path_layout')->default('friendly');
        });
    }

    public function down(): void
    {
        Schema::table('drives', fn (Blueprint $table) => $table->dropColumn('path_layout'));
        foreach (['materials', 'variants', 'package_derivatives'] as $name) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("DROP TRIGGER IF EXISTS {$name}_keep_uuid ON {$name}");
            }
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('uuid'));
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION opal_keep_uuid()');
            DB::statement('DROP FUNCTION opal_uuid_v7()');
        }
    }
};
