<?php

use App\Enums\ResultSource;
use App\Enums\ResultType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('request_id')->constrained('requests')->cascadeOnDelete();
            $table->unsignedInteger('result_number');
            $table->enum('type', ResultType::values());

            $table->foreignUuid('file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->text('text_value')->nullable();

            $table->enum('source', ResultSource::values());
            $table->unsignedInteger('latency_ms')->nullable();

            // Acts as the row's creation timestamp (see Result model).
            $table->timestamp('received_at')->nullable();

            $table->unique(['request_id', 'result_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('results');
    }
};
