<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Model-generated vectors are kept beside the records they describe. The
 * provider/model/dimension columns make a model change an explicit re-index,
 * while the source digest makes unchanged records cheap to skip.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        }

        Schema::create('embeddings', function (Blueprint $table): void {
            $table->id();
            $table->morphs('embeddable');
            $table->string('kind', 64);
            $table->string('provider', 64);
            $table->string('model');
            $table->unsignedSmallInteger('dimensions');
            $table->string('source_digest', 64);
            $table->text('source_text');
            $table->foreignId('image_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['embeddable_type', 'embeddable_id', 'kind', 'provider', 'model'],
                'embeddings_embeddable_profile_unique',
            );
            $table->index(['kind', 'provider', 'model'], 'embeddings_profile_index');
            $table->index('source_digest');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE embeddings ADD COLUMN embedding vector(1024) NOT NULL');
            DB::statement('CREATE INDEX embeddings_cosine_hnsw_idx ON embeddings USING hnsw (embedding vector_cosine_ops)');
        } else {
            Schema::table('embeddings', fn (Blueprint $table) => $table->text('embedding'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('embeddings');
    }
};
