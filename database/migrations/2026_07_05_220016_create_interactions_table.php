<?php

use App\Enums\InteractionKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only engagement ledger (downloads / external click-throughs).
        Schema::create('interactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('kind', InteractionKind::values())->index();
            $table->string('user_ref')->nullable()->index();
            $table->foreignUuid('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignUuid('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignUuid('result_id')->nullable()->constrained('results')->nullOnDelete();

            // Immutable event: only a creation timestamp (see Interaction model).
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interactions');
    }
};
