<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Files attached to a file/image/video/bundle input, ordered by position.
        Schema::create('order_input_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_input_id')->constrained('order_inputs')->cascadeOnDelete();
            $table->foreignUuid('file_id')->constrained('files')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->unique(['order_input_id', 'file_id']);
            $table->index('file_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_input_files');
    }
};
