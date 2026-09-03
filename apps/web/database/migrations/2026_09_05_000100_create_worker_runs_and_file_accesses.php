<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Worker runs are the business record of background work: what was asked,
 * for which record, by whom, what happened. File accesses record every read
 * of a library file, from the web now and from PrismFS later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worker_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 64);
            $table->string('status', 24)->default('queued');
            $table->nullableMorphs('subject');
            $table->jsonb('payload')->nullable();
            $table->jsonb('result')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('queue', 64)->nullable();
            $table->timestamp('queued_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index('queued_at');
        });

        Schema::create('file_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 24);
            $table->string('action', 24)->default('read');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('drive_id')->nullable()->constrained()->nullOnDelete();
            $table->string('principal')->nullable();
            $table->string('path', 1024)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->timestamp('accessed_at');

            $table->index(['file_id', 'accessed_at']);
            $table->index(['user_id', 'accessed_at']);
            $table->index('accessed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_accesses');
        Schema::dropIfExists('worker_runs');
    }
};
