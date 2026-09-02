<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->index('current_tenant_id');
        });

        Schema::table('tenant_user', function (Blueprint $table) {
            $table->index('user_id');
        });

        Schema::table('media', function (Blueprint $table) {
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropIndex(['tenant_id']);
        });

        Schema::table('tenant_user', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['current_tenant_id']);
        });
    }
};
