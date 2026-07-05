<?php

use App\Enums\CommentSentiment;
use App\Enums\CommentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_version_id')->constrained('service_versions')->cascadeOnDelete();
            $table->string('user_ref');
            $table->text('body');
            $table->enum('sentiment', CommentSentiment::values())
                ->default(CommentSentiment::Neutral->value);
            $table->enum('status', CommentStatus::values())
                ->default(CommentStatus::Published->value)
                ->index();

            // Self reference for threaded replies.
            $table->uuid('parent_id')->nullable();

            $table->timestamps();

            $table->foreign('parent_id')
                ->references('id')->on('service_comments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_comments');
    }
};
