<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A supplier is a commercial entity with products. A source is an origin of
 * files (a supplier's own channel, a texture platform, in-house work, an AI
 * model) together with whatever is known about its terms. Licence rules are
 * derived later from provenance, so terms stay descriptive here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('code', 16)->unique();
            $table->string('website')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('kind')->default('unknown');
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->string('url')->nullable();
            $table->string('licence_url')->nullable();
            $table->jsonb('terms')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
        Schema::dropIfExists('suppliers');
    }
};
