<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8's analytics queries and services:refresh-metrics filter orders
     * by (service_id, source[, status]) and (service_version_id, source[,
     * status]), and window service_votes/orders by created_at (the
     * trending_rank formula's last-N-days cutoff) -- none of which the
     * existing single-column indexes (service_id, service_version_id,
     * status alone) cover as a leftmost prefix.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['service_id', 'source', 'status']);
            $table->index(['service_version_id', 'source', 'status']);
            $table->index('created_at');
            // trending_rank's per-signal counts filter by (source, status,
            // created_at) across ALL services and group by service_id --
            // service_id isn't a filter there, so the two indexes above
            // (which lead with it) don't help this access pattern.
            $table->index(['source', 'status', 'created_at']);
        });

        Schema::table('service_votes', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['service_id', 'source', 'status']);
            $table->dropIndex(['service_version_id', 'source', 'status']);
            $table->dropIndex(['created_at']);
            $table->dropIndex(['source', 'status', 'created_at']);
        });

        Schema::table('service_votes', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
