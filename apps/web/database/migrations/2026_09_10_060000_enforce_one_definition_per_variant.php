<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('definitions', function (Blueprint $table) {
            $table->dropUnique(['variant_id', 'generator']);
            $table->unique('variant_id');
        });
    }

    public function down(): void
    {
        Schema::table('definitions', function (Blueprint $table) {
            $table->dropUnique(['variant_id']);
            $table->unique(['variant_id', 'generator']);
        });
    }
};
