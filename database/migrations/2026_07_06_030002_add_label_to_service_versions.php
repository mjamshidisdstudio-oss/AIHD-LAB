<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A free-text operator label for a version pill (e.g. "v3 - faster
     * model"), distinct from the immutable version_no. Editable regardless
     * of the version's status -- it's bookkeeping metadata, not frozen
     * configuration, so it deliberately does NOT go through
     * ServiceVersion::ensureEditable().
     */
    public function up(): void
    {
        Schema::table('service_versions', function (Blueprint $table) {
            $table->string('label')->nullable()->after('version_no');
        });
    }

    public function down(): void
    {
        Schema::table('service_versions', function (Blueprint $table) {
            $table->dropColumn('label');
        });
    }
};
