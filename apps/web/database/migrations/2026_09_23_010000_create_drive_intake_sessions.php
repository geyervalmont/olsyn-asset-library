<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drive_intake_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('status', 24)->default('open');
            $table->timestamp('expires_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'expires_at']);
        });
        Schema::create('drive_intake_files', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('drive_intake_session_id')->constrained()->cascadeOnDelete();
            $table->string('path', 512);
            $table->char('path_hash', 64);
            $table->unsignedBigInteger('bytes');
            $table->char('sha256', 64);
            $table->string('disk');
            $table->string('object_key');
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();
            $table->unique(['drive_intake_session_id', 'path_hash']);
        });
        Schema::create('drive_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('device_id');
            $table->foreignId('token_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();
            $table->string('machine', 120);
            $table->string('version', 40)->nullable();
            $table->string('state', 24);
            $table->string('mount_path', 255)->nullable();
            $table->string('error_code', 40)->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['user_id', 'device_id']);
        });
        Schema::table('client_sessions', function (Blueprint $table): void {
            $table->string('drive_status', 24)->nullable();
            $table->string('mount_path', 255)->nullable();
            $table->string('drive_error', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('client_sessions', fn (Blueprint $table) => $table->dropColumn(['drive_status', 'mount_path', 'drive_error']));
        Schema::dropIfExists('drive_connections');
        Schema::dropIfExists('drive_intake_files');
        Schema::dropIfExists('drive_intake_sessions');
    }
};
