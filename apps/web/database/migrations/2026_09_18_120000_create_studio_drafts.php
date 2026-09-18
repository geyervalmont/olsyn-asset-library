<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_drafts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('state')->default('active');
            $table->unsignedBigInteger('head_id')->nullable();
            $table->foreignId('source_representation_id')->nullable()->constrained('representations')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'user_id', 'state']);
        });
        Schema::create('studio_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('studio_draft_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->json('document');
            $table->json('artifacts')->nullable();
            $table->foreignId('representation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('synthesis_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('studio_revision_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status')->default('queued')->index();
            $table->string('stage')->default('queued');
            $table->text('worker_token');
            $table->json('allocation')->nullable();
            $table->json('artifacts')->nullable();
            $table->json('manifest')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('deadline_at');
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('synthesis_runs');
        Schema::dropIfExists('studio_revisions');
        Schema::dropIfExists('studio_drafts');
    }
};
