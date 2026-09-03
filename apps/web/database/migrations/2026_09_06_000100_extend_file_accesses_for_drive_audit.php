<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('file_accesses', function (Blueprint $table) {
            $table->unsignedBigInteger('file_id')->nullable()->change();
            $table->string('result', 16)->nullable()->after('action');
            $table->string('request_id', 64)->nullable()->after('drive_id');
            $table->decimal('duration_ms', 12, 3)->nullable()->after('bytes');
            $table->index(['drive_id', 'accessed_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('create unique index file_accesses_drive_request_unique on file_accesses (drive_id, request_id) where request_id is not null');
        } else {
            Schema::table('file_accesses', fn (Blueprint $table) => $table->unique(['drive_id', 'request_id'], 'file_accesses_drive_request_unique'));
        }
    }

    public function down(): void
    {
        Schema::table('file_accesses', function (Blueprint $table) {
            $table->dropIndex('file_accesses_drive_request_unique');
            $table->dropIndex(['drive_id', 'accessed_at']);
            $table->dropColumn(['result', 'request_id', 'duration_ms']);
        });
    }
};
