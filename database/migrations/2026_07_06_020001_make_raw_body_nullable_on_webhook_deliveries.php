<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The retention janitor nulls raw_body after 30 days (the receipt --
     * outcome/http_status/timestamps -- is kept forever), so the column must
     * be able to hold null. received_at gets an index too: the janitor's
     * "older than 30 days" scan would otherwise be a full table scan of a
     * log that only grows.
     */
    public function up(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->text('raw_body')->nullable()->change();
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropIndex(['received_at']);
            $table->text('raw_body')->nullable(false)->change();
        });
    }
};
