<?php

use App\Enums\ServiceOutputType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_outputs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_version_id')->constrained('service_versions')->cascadeOnDelete();
            $table->unsignedInteger('result_number');
            $table->enum('type', ServiceOutputType::values());

            $table->timestamps();

            $table->unique(['service_version_id', 'result_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_outputs');
    }
};
