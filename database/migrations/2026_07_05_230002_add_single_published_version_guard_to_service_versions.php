<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_versions', function (Blueprint $table) {
            // Virtual generated column that equals the service_id only while
            // this version is published, and NULL otherwise. Because MySQL
            // unique indexes allow repeated NULLs, the UNIQUE index below
            // constrains ONLY the published rows: two published versions of the
            // same service would share the same service_id value and collide.
            // This makes "at most one published version per service" a database
            // invariant, not just an application rule.
            //
            // VIRTUAL (not STORED) so adding the column is an in-place metadata
            // change; a STORED column forces a full table copy, which fails on
            // service_versions because it is the target of incoming foreign keys
            // (orders' composite FK and services.current_version_id).
            $table->uuid('published_service_id')->nullable()
                ->virtualAs("(case when `status` = 'published' then `service_id` else null end)");

            $table->unique('published_service_id');
        });
    }

    public function down(): void
    {
        Schema::table('service_versions', function (Blueprint $table) {
            $table->dropUnique(['published_service_id']);
            $table->dropColumn('published_service_id');
        });
    }
};
