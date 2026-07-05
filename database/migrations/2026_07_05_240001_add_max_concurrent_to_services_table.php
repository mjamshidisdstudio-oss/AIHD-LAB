<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // Upper bound on in-flight external requests for this service; the
            // dispatch job releases back to the queue when the cap is reached.
            $table->unsignedInteger('max_concurrent')->default(3)->after('consecutive_failures');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('max_concurrent');
        });
    }
};
