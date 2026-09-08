<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two additions for the USDZ model.
 *
 * A definition is a material described rather than scanned: a Dulux colour is a
 * CIELAB value and a sheen, a generated tile is a generator and its parameters.
 * Baking one produces the same canonical representation a scanned set arrives
 * as, so nothing downstream needs to know which it was.
 *
 * A package is one built USDZ — the archive's index. It is deliberately not the
 * source of truth for anything: every row here can be rebuilt from the variant
 * it belongs to, and the file it points at can be rebuilt from the same. What
 * the table buys is knowing what exists without listing a bucket of 26,000
 * objects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();

            // Which baker reads the parameters. A paint and a brick generator
            // are the same shape here and differ only in how much work the bake
            // does.
            $table->string('generator', 64);
            $table->string('generator_version', 32)->nullable();
            $table->jsonb('parameters');

            // Set when the bake has produced files, so a stale definition is
            // visible rather than silently serving an old result.
            $table->timestamp('baked_at')->nullable();
            $table->string('baked_digest', 64)->nullable();

            $table->timestamps();

            $table->index(['generator', 'baked_at']);
            $table->unique(['variant_id', 'generator']);
        });

        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();

            // Immutable once written: a rebuild is a new row, never an edit, so
            // a reference to a package can never resolve to different bytes.
            $table->unsignedInteger('revision');
            $table->string('object_key', 512);
            $table->string('sha256', 64);
            $table->unsignedBigInteger('bytes');

            // Which resolutions the package actually contains, so the gap
            // between intended and built tiers is queryable rather than
            // discovered by opening the file.
            $table->jsonb('tiers');

            // The toolbox that wrote it. A package built by an older builder is
            // findable when a mapping changes.
            $table->string('builder', 64)->nullable();
            $table->string('builder_version', 32)->nullable();

            // Losses recorded at build time, in the same shape the toolbox
            // reports them. Null means not yet assessed, [] means clean.
            $table->jsonb('losses')->nullable();

            $table->timestamp('built_at')->nullable();
            $table->timestamps();

            $table->unique(['variant_id', 'revision']);
            $table->unique('sha256');
            $table->index(['variant_id', 'built_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
        Schema::dropIfExists('definitions');
    }
};
