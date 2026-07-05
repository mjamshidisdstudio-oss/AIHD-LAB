<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_inputs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUuid('input_id')->constrained('service_inputs')->cascadeOnDelete();

            // Scalar answers. Non-scalar answers (files, options) live in the
            // order_input_files / order_input_options tables instead.
            $table->text('value_text')->nullable();
            $table->boolean('value_bool')->nullable();

            // MySQL 8 stored generated column: counts how many scalar slots are
            // populated for this answer. Driven purely from same-row columns.
            $table->unsignedTinyInteger('value_fill_count')
                ->storedAs('(value_text is not null) + (value_bool is not null)');

            $table->timestamps();

            $table->unique(['order_id', 'input_id']);
        });

        // CHECK backed by the generated column: a scalar answer may never carry
        // both a text and a boolean value at once. The complementary lower bound
        // ("exactly one when the input type requires a scalar") is enforced in
        // the application layer, because the deciding input type lives on
        // service_inputs and a CHECK can only see columns of its own row.
        DB::statement(
            'ALTER TABLE order_inputs '
            .'ADD CONSTRAINT chk_order_inputs_single_scalar CHECK (value_fill_count <= 1)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('order_inputs');
    }
};
