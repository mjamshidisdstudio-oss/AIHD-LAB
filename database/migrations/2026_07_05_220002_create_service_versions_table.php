<?php

use App\Enums\ServiceVersionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_id')->constrained('services')->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->enum('status', ServiceVersionStatus::values())
                ->default(ServiceVersionStatus::Draft->value);

            $table->unsignedInteger('coin_cost')->default(0);
            $table->unsignedInteger('regenerate_limit')->default(0);
            $table->unsignedInteger('response_timeout_s');
            $table->unsignedInteger('get_interval_s');
            $table->unsignedInteger('max_get_attempts');

            $table->string('post_url')->nullable();
            $table->string('get_url')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            // One version number per service.
            $table->unique(['service_id', 'version_no']);
            // Composite key referenced by orders' composite FK so an order's
            // version is guaranteed to belong to the order's service.
            $table->unique(['id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_versions');
    }
};
