<?php

use App\Enums\FileKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Objects stored on the "media" S3 disk: user uploads and results.
        Schema::create('files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('kind', FileKind::values())->index();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('mime');
            $table->string('path');
            $table->unsignedBigInteger('size');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
