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

            // Denormalised from the input's type (maintained by OrderInput::booted):
            // true when the input bears a scalar answer (text/boolean), false
            // otherwise. Copied onto the row because the CHECK below can only see
            // columns of its own row, and the deciding type lives on service_inputs.
            $table->boolean('expects_scalar')->default(false);

            // MySQL 8 stored generated column: counts how many scalar slots are
            // populated for this answer. Driven purely from same-row columns.
            $table->unsignedTinyInteger('value_fill_count')
                ->storedAs('(value_text is not null) + (value_bool is not null)');

            $table->timestamps();

            $table->unique(['order_id', 'input_id']);
        });

        // CHECK backed by the generated column: a value-bearing input must carry
        // EXACTLY ONE scalar value (value_fill_count = 1), and a non-value-bearing
        // input must carry NONE (value_fill_count = 0). Since expects_scalar is
        // 0/1, requiring value_fill_count = expects_scalar rejects both-null and
        // both-set answers for value-bearing inputs at the database layer.
        DB::statement(
            'ALTER TABLE order_inputs '
            .'ADD CONSTRAINT chk_order_inputs_exactly_one_scalar '
            .'CHECK (value_fill_count = expects_scalar)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('order_inputs');
    }
};
