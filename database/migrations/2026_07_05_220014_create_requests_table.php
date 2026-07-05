<?php

use App\Enums\FailureStage;
use App\Enums\RequestStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One external attempt for an order (POST then GET polling loop).
        Schema::create('requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedInteger('attempt_no');
            $table->string('external_order_id')->nullable()->index();

            $table->enum('status', RequestStatus::values())
                ->default(RequestStatus::Queued->value)
                ->index();
            $table->enum('failure_stage', FailureStage::values())->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->unsignedInteger('get_poll_count')->default(0);

            $table->timestamps();

            $table->unique(['order_id', 'attempt_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requests');
    }
};
