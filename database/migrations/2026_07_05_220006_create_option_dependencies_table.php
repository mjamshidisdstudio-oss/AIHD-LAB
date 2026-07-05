<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Edges gating one option on the selection of a parent option (used to
        // build cascading selects such as room_type -> style).
        Schema::create('option_dependencies', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('option_id');
            $table->uuid('parent_option_id');

            $table->timestamps();

            $table->unique(['option_id', 'parent_option_id']);
            $table->index('parent_option_id');

            $table->foreign('option_id')
                ->references('id')->on('service_input_options')
                ->cascadeOnDelete();
            $table->foreign('parent_option_id')
                ->references('id')->on('service_input_options')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('option_dependencies');
    }
};
