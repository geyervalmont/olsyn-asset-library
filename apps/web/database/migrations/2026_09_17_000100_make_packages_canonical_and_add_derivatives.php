<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make immutable USDZ packages the published content boundary.
 *
 * Material versions pin one package per variant. Consumer-specific files are
 * reproducible derivatives of that package, keyed by package, target, quality
 * and converter version. Authoring representations remain the inputs used to
 * build the next package, but are no longer what a published drive exposes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_version_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained()->restrictOnDelete();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();

            $table->unique(['material_version_id', 'package_id'], 'version_package_unique');
            $table->unique(['material_version_id', 'variant_id'], 'version_variant_package_unique');
            $table->index(['package_id', 'material_version_id']);
        });

        Schema::create('package_derivatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('target_id')->constrained()->restrictOnDelete();
            $table->foreignId('quality_tier_id')->constrained()->restrictOnDelete();
            $table->string('source_sha256', 64);
            $table->string('converter', 96);
            $table->string('converter_version', 64);
            $table->jsonb('losses')->nullable();
            $table->timestamp('built_at');
            $table->timestamps();

            $table->unique(
                ['package_id', 'target_id', 'quality_tier_id', 'converter', 'converter_version'],
                'package_derivative_cache_key',
            );
            $table->index(['source_sha256', 'target_id', 'quality_tier_id'], 'package_derivative_source_index');
        });

        Schema::create('package_derivative_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_derivative_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->constrained()->restrictOnDelete();
            $table->foreignId('map_role_id')->constrained()->restrictOnDelete();
            $table->string('colour_space', 32)->nullable();

            $table->unique(['package_derivative_id', 'map_role_id'], 'package_derivative_role_unique');
            $table->index('file_id');
        });

        // Do not guess a package for historical representation-based versions.
        // The latest package may not contain the content that old version
        // approved. Rebuild and republish to establish a truthful package pin.
    }

    public function down(): void
    {
        Schema::dropIfExists('package_derivative_files');
        Schema::dropIfExists('package_derivatives');
        Schema::dropIfExists('material_version_packages');
    }
};
