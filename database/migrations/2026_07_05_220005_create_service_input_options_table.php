<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_input_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('input_id')->constrained('service_inputs')->cascadeOnDelete();
            $table->string('slug');
            $table->string('label');
            $table->string('color')->nullable();
            $table->string('icon')->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique(['input_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_input_options');
    }
};
