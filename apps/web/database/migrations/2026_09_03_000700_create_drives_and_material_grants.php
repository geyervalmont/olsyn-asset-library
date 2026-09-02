<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A drive is a projected namespace (a PrismFS mount, an SMB share) that sees
 * a subset of the library. Material visibility is library-wide by default;
 * restricted materials are visible only to their grantees: users, drives, or
 * whole tenants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drives', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('root_path')->default('/materials');
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('target_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('materials', function (Blueprint $table) {
            $table->string('visibility', 32)->default('library')->after('status');
            $table->index('visibility');
        });

        Schema::create('material_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained()->cascadeOnDelete();
            $table->morphs('grantee');
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['material_id', 'grantee_type', 'grantee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_grants');
        Schema::table('materials', function (Blueprint $table) {
            $table->dropIndex(['visibility']);
            $table->dropColumn('visibility');
        });
        Schema::dropIfExists('drives');
    }
};
