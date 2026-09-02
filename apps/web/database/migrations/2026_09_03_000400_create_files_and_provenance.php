<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files are immutable, content-addressed records of bytes in object storage.
 * Provenance is an event log: every event names its subject, who or what
 * acted, with which tool, and which files went in and came out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->string('sha256', 64)->unique();
            $table->string('disk', 64);
            $table->string('object_key', 512)->unique();
            $table->string('kind', 32);
            $table->string('mime_type', 128);
            $table->string('extension', 16)->nullable();
            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('bytes');
            $table->unsignedInteger('width_px')->nullable();
            $table->unsignedInteger('height_px')->nullable();
            $table->string('colour_space', 32)->nullable();
            $table->unsignedSmallInteger('bit_depth')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('kind');
            $table->index('mime_type');
        });

        Schema::create('provenance_actions', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('provenance_events', function (Blueprint $table) {
            $table->id();
            $table->morphs('subject');
            $table->string('action', 64);
            $table->string('actor_type', 32);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('tool')->nullable();
            $table->string('tool_version', 64)->nullable();
            $table->string('model')->nullable();
            $table->jsonb('parameters')->nullable();
            $table->foreignId('source_id')->nullable()->constrained('sources')->nullOnDelete();
            $table->string('source_url', 2048)->nullable();
            $table->string('external_ref')->nullable();
            $table->string('job_id')->nullable();
            $table->foreignId('parent_event_id')->nullable()->constrained('provenance_events')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('action')->references('slug')->on('provenance_actions')->restrictOnDelete();
            $table->index('action');
            $table->index('occurred_at');
            $table->index(['actor_type', 'actor_id']);
            $table->index('job_id');
        });

        Schema::create('provenance_event_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provenance_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->constrained()->restrictOnDelete();
            $table->string('direction', 8);
            $table->string('role', 64)->nullable();

            $table->unique(['provenance_event_id', 'file_id', 'direction', 'role'], 'provenance_event_files_edge_unique');
            $table->index(['file_id', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provenance_event_files');
        Schema::dropIfExists('provenance_events');
        Schema::dropIfExists('provenance_actions');
        Schema::dropIfExists('files');
    }
};
