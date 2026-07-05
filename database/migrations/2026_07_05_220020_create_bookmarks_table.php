<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookmarks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_id')->constrained('services')->cascadeOnDelete();
            $table->string('user_ref');

            // Immutable event: only a creation timestamp (see Bookmark model).
            $table->timestamp('created_at')->nullable();

            $table->unique(['service_id', 'user_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookmarks');
    }
};
