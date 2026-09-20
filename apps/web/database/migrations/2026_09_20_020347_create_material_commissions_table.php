<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('material_commissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 100);
            $table->string('request_hash', 64)->index();
            $table->string('trace_id', 32);
            $table->uuid('bender_job_id');
            $table->text('brief');
            $table->string('status')->default('requested');
            $table->json('artifacts')->nullable();
            $table->json('provenance')->nullable();
            $table->foreignId('studio_draft_id')->nullable()->constrained()->nullOnDelete();
            $table->unique(['user_id', 'idempotency_key']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('material_commissions');
    }
};
