<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_file_ingests', function (Blueprint $table) {
            $table->id();
            $table->string('source_path', 1024)->unique();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->timestamp('mtime')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->foreignId('file_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->index();
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_file_ingests');
    }
};
