<?php

use App\Enums\WebhookOutcome;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit log of every inbound webhook, including rejected ones (which
        // may not resolve to a known service/request — hence nullable FKs).
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignUuid('request_id')->nullable()->constrained('requests')->nullOnDelete();
            $table->string('external_order_id')->nullable()->index();
            $table->unsignedInteger('result_number')->nullable();

            $table->enum('outcome', WebhookOutcome::values())->index();
            $table->unsignedSmallInteger('http_status');
            $table->text('raw_body');

            // Acts as the row's creation timestamp (see WebhookDelivery model).
            $table->timestamp('received_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
