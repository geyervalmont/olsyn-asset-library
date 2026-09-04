<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_links', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 9)->unique();
            $table->string('secret_hash', 64);
            $table->string('client', 40);
            $table->string('machine', 120)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('token_id')->nullable();
            $table->text('token_plain')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('client_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 40);
            $table->string('machine', 120);
            $table->string('app_version', 40)->nullable();
            $table->string('document', 255)->nullable();
            $table->unsignedBigInteger('token_id')->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'ended_at', 'last_seen_at']);
        });

        Schema::create('client_commands', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issued_by')->constrained('users')->cascadeOnDelete();
            $table->string('type', 20);
            $table->json('payload');
            $table->string('status', 20)->default('queued');
            $table->json('result')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('acked_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['client_session_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_commands');
        Schema::dropIfExists('client_sessions');
        Schema::dropIfExists('device_links');
    }
};
