<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Materials are library records owned by OPAL, never by a tenant. A material
 * has one or more variants; a variant is what a client application consumes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
            DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
        }

        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('slug');
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('collection')->nullable();
            $table->string('supplier_product_code')->nullable();
            $table->text('description')->nullable();
            $table->string('material_type')->nullable();
            $table->string('form')->nullable();
            $table->decimal('tile_width_mm', 10, 2)->nullable();
            $table->decimal('tile_height_mm', 10, 2)->nullable();
            $table->decimal('thickness_mm', 10, 2)->nullable();
            $table->string('repeat_type')->nullable();
            $table->string('install_pattern')->nullable();
            $table->decimal('sqm_cost', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('lead_time')->nullable();
            $table->jsonb('specifications')->nullable();
            $table->string('status', 32)->default('draft');
            $table->foreignId('contributed_by_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('contributed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('search_text')->default('');
            $table->timestamps();

            $table->index('slug');
            $table->index('supplier_product_code');
            $table->index('status');
        });

        Schema::create('variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained()->cascadeOnDelete();
            $table->string('code', 96)->unique();
            $table->string('token', 32);
            $table->string('name');
            $table->unsignedSmallInteger('position')->default(0);
            $table->decimal('tile_width_mm', 10, 2)->nullable();
            $table->decimal('tile_height_mm', 10, 2)->nullable();
            $table->decimal('thickness_mm', 10, 2)->nullable();
            $table->string('repeat_type')->nullable();
            $table->string('install_pattern')->nullable();
            $table->string('dominant_hex', 7)->nullable();
            $table->string('colour_family')->nullable();
            $table->decimal('colour_l', 6, 2)->nullable();
            $table->decimal('colour_a', 6, 2)->nullable();
            $table->decimal('colour_b', 6, 2)->nullable();
            $table->text('search_text')->default('');
            $table->timestamps();

            $table->unique(['material_id', 'token']);
            $table->index('colour_family');
        });

        Schema::create('variant_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_type_id')->constrained()->restrictOnDelete();
            $table->string('value');
            $table->string('supplier_code')->nullable();
            $table->string('supplier_name')->nullable();
            $table->foreignId('ref_variant_id')->nullable()->constrained('variants')->nullOnDelete();
            $table->timestamps();

            $table->unique(['variant_id', 'variant_type_id']);
            $table->index(['variant_type_id', 'value']);
        });

        Schema::create('aliases', function (Blueprint $table) {
            $table->id();
            $table->morphs('aliasable');
            $table->string('code', 96)->unique();
            $table->string('reason')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach (['materials', 'variants'] as $tableName) {
                DB::statement("ALTER TABLE {$tableName} ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('simple', coalesce(search_text, ''))) STORED");
                DB::statement("CREATE INDEX {$tableName}_search_vector_idx ON {$tableName} USING GIN (search_vector)");
                DB::statement("CREATE INDEX {$tableName}_search_text_trgm_idx ON {$tableName} USING GIN (search_text gin_trgm_ops)");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('aliases');
        Schema::dropIfExists('variant_attributes');
        Schema::dropIfExists('variants');
        Schema::dropIfExists('materials');
    }
};
