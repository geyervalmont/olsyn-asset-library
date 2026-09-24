<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_access', function (Blueprint $table) {
            $table->timestamp('email_queued_at')->nullable();
            $table->timestamp('email_sent_at')->nullable();
            $table->string('email_error', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspace_access', fn (Blueprint $table) => $table->dropColumn(['email_queued_at', 'email_sent_at', 'email_error']));
    }
};
