<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A platform is a consumer (Revit, Enscape, Omniverse). A platform identity
 * records what a variant is called there, so an external name resolves back
 * to the library and its canonical files.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platforms', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->foreignId('target_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('platform_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('platform_id')->constrained()->restrictOnDelete();
            $table->string('external_id')->nullable();
            $table->string('external_name')->nullable();
            $table->jsonb('payload')->nullable();
            $table->string('status', 32)->default('confirmed');
            $table->timestamps();

            $table->unique(['variant_id', 'platform_id']);
            $table->unique(['platform_id', 'external_id']);
            $table->index(['platform_id', 'external_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_identities');
        Schema::dropIfExists('platforms');
    }
};
