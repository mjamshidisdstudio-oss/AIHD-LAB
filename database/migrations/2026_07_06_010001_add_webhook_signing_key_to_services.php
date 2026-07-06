<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // The SECOND service secret. Unlike the bcrypt-hashed service_secret
            // (which can only verify a pasted value), this key is encrypted at
            // rest and therefore RETRIEVABLE: webhook HMAC verification and the
            // storage-API key auth must recompute against the raw value, which a
            // one-way hash can never yield. Never generated — pasted by an
            // operator. TEXT because the encrypted payload is longer than the key.
            $table->text('webhook_signing_key')->nullable()->after('service_secret');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('webhook_signing_key');
        });
    }
};
