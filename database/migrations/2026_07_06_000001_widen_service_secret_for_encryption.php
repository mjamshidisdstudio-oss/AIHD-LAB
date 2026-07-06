<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A webhook HMAC needs the RAW per-service secret, which a bcrypt hash can
     * never yield. Phase 4 therefore stores service_secret encrypted (reversible)
     * instead of hashed — the "agreed mechanism" from the spec. Encrypted values
     * are far longer than the old 60-char bcrypt hash, so widen the column to TEXT.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->text('service_secret')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('service_secret')->nullable()->change();
        });
    }
};
