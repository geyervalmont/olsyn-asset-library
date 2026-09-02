<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PrismFS fetches a drive's manifest with a bearer token. Only its hash is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drives', function (Blueprint $table) {
            $table->string('access_token_hash', 64)->nullable()->unique()->after('is_active');
            $table->timestamp('token_issued_at')->nullable()->after('access_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('drives', function (Blueprint $table) {
            $table->dropColumn(['access_token_hash', 'token_issued_at']);
        });
    }
};
