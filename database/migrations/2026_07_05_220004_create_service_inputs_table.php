<?php

use App\Enums\ServiceInputType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_inputs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_version_id')->constrained('service_versions')->cascadeOnDelete();
            $table->string('slug');
            $table->string('title');
            $table->enum('type', ServiceInputType::values());
            $table->boolean('required')->default(false);
            $table->boolean('multi_select')->default(false);
            $table->boolean('searchable')->default(false);

            // Self reference: this input is only shown when another input on the
            // same version holds `depends_on_value`.
            $table->uuid('depends_on_input_id')->nullable();
            $table->string('depends_on_value')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->json('config')->nullable();

            $table->timestamps();

            $table->unique(['service_version_id', 'slug']);

            $table->foreign('depends_on_input_id')
                ->references('id')
                ->on('service_inputs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_inputs');
    }
};
