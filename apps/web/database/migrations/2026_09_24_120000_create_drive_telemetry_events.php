<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drive_telemetry_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('device_id');
            $table->uuid('event_id');
            $table->string('kind', 16);
            $table->string('stage', 24);
            $table->string('code', 40)->nullable();
            $table->string('version', 40);
            $table->timestamp('occurred_at');
            $table->timestamp('received_at')->index();
            $table->json('details');
            $table->unique(['user_id', 'event_id']);
            $table->index(['user_id', 'device_id', 'received_at'], 'drive_telemetry_device_history');
            $table->index(['kind', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drive_telemetry_events');
    }
};
