<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which option(s) an order picked for a select input.
        Schema::create('order_input_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_input_id')->constrained('order_inputs')->cascadeOnDelete();

            $table->uuid('option_id');

            $table->timestamps();

            $table->unique(['order_input_id', 'option_id']);
            $table->index('option_id');

            $table->foreign('option_id')
                ->references('id')->on('service_input_options')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_input_options');
    }
};
