<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Schema-gap decisions (see PR #10's list): gallery/before-after and a
     * tagline are worth adding for a marketplace whose whole premise is
     * visual generation results; a normalized service_images table was
     * rejected since the design only ever shows one before/after pair.
     */
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('tagline')->nullable()->after('description');
            $table->json('gallery')->nullable()->after('image_url');
            $table->string('before_image_url')->nullable()->after('gallery');
            $table->string('after_image_url')->nullable()->after('before_image_url');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['tagline', 'gallery', 'before_image_url', 'after_image_url']);
        });
    }
};
