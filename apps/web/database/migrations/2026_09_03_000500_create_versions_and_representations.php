<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A representation is the concrete file set a consumer uses: one variant, at
 * one target, at one quality. Versions are immutable snapshots of approved
 * representations; the material's current version is a pointer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('targets', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_canonical')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('quality_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->unsignedInteger('pixels')->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('map_roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->string('colour_space', 32)->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('material_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('status', 32)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['material_id', 'number']);
            $table->index(['material_id', 'status']);
        });

        Schema::table('materials', function (Blueprint $table) {
            $table->foreignId('current_version_id')->nullable()->constrained('material_versions')->nullOnDelete();
        });

        Schema::create('representations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('target_id')->constrained()->restrictOnDelete();
            $table->foreignId('quality_tier_id')->constrained()->restrictOnDelete();
            $table->string('kind', 32);
            $table->string('review_state', 32)->default('candidate');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['variant_id', 'target_id', 'quality_tier_id', 'review_state'], 'representations_key_state_index');
        });

        Schema::create('representation_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('representation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->constrained()->restrictOnDelete();
            $table->foreignId('map_role_id')->constrained()->restrictOnDelete();
            $table->string('colour_space', 32)->nullable();

            $table->unique(['representation_id', 'map_role_id']);
            $table->index('file_id');
        });

        Schema::create('material_version_representations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('representation_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('target_id')->constrained()->restrictOnDelete();
            $table->foreignId('quality_tier_id')->constrained()->restrictOnDelete();

            $table->unique(['material_version_id', 'representation_id'], 'version_representation_unique');
            $table->unique(['material_version_id', 'variant_id', 'target_id', 'quality_tier_id'], 'version_representation_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_version_representations');
        Schema::dropIfExists('representation_files');
        Schema::dropIfExists('representations');
        Schema::table('materials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_version_id');
        });
        Schema::dropIfExists('material_versions');
        Schema::dropIfExists('map_roles');
        Schema::dropIfExists('quality_tiers');
        Schema::dropIfExists('targets');
    }
};
