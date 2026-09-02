<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy canonical keys are long; keep them resolvable as aliases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aliases', function (Blueprint $table) {
            $table->string('code', 191)->change();
        });
    }

    public function down(): void
    {
        Schema::table('aliases', function (Blueprint $table) {
            $table->string('code', 96)->change();
        });
    }
};
