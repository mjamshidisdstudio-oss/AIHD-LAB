<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_votes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignUuid('service_version_id')->constrained('service_versions')->cascadeOnDelete();
            $table->string('user_ref');

            // Upvote (+1) or downvote (-1).
            $table->smallInteger('value');

            $table->timestamps();

            // One vote per user per service (across all versions).
            $table->unique(['service_id', 'user_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_votes');
    }
};
