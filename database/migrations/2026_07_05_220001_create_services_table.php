<?php

use App\Enums\ServiceKind;
use App\Enums\ServiceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();
            $table->enum('kind', ServiceKind::values());
            $table->string('external_url')->nullable();
            $table->string('category')->index();

            // Stored hashed; never persisted in clear text (see Service model cast).
            $table->string('service_secret')->nullable();

            $table->enum('status', ServiceStatus::values())
                ->default(ServiceStatus::Active->value)
                ->index();
            $table->unsignedInteger('consecutive_failures')->default(0);

            // Points at the version currently served to end users. The FK is
            // added in a later migration because service_versions references
            // services, so the table must exist first (circular dependency).
            $table->uuid('current_version_id')->nullable()->index();

            // Denormalised read columns kept in sync from the event ledgers so
            // list/detail pages avoid aggregate queries.
            $table->unsignedInteger('vote_up')->default(0);
            $table->unsignedInteger('vote_down')->default(0);
            // Nullable: unknown until the service has completed an order.
            $table->unsignedInteger('avg_latency_ms')->nullable();
            $table->unsignedInteger('trending_rank')->nullable()->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
