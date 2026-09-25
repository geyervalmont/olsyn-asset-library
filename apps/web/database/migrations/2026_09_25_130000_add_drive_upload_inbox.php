<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drive_intake_sessions', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('is_inbox')->default(false);
            $table->timestamp('expires_at')->nullable()->change();
            $table->index(['tenant_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('drive_intake_sessions', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'status', 'id']);
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn('is_inbox');
        });
        // Preserve non-expiring historical batches; rolling back must not discard uploads.
    }
};
