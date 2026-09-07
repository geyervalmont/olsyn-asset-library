<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How many files the legacy library holds for a material, whether or not
     * they have been staged here. Without it, a material with nothing looks
     * the same as a material whose files simply have not arrived.
     */
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table): void {
            $table->unsignedInteger('legacy_files_expected')->nullable()->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table): void {
            $table->dropColumn('legacy_files_expected');
        });
    }
};
