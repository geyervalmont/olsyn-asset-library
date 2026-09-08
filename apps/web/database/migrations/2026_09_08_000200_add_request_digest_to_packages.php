<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the package was built from, as a digest of the build manifest.
 *
 * Without it "has this variant changed since it was packaged?" can only be
 * answered by rebuilding and comparing, which for 26,000 variants is the
 * difference between a pipeline that can be re-run freely and one nobody dares
 * start.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->string('request_digest', 64)->nullable()->after('sha256');
            $table->index(['variant_id', 'request_digest']);
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropIndex(['variant_id', 'request_digest']);
            $table->dropColumn('request_digest');
        });
    }
};
