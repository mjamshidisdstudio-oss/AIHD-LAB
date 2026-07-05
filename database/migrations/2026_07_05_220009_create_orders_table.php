<?php

use App\Enums\EntryMode;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_ref')->nullable()->index();

            // Columns declared without ->constrained() so the composite FK
            // below can also reference service_id.
            $table->foreignUuid('service_id');
            $table->foreignUuid('service_version_id');

            $table->enum('status', OrderStatus::values())
                ->default(OrderStatus::Processing->value)
                ->index();
            $table->enum('source', OrderSource::values())
                ->default(OrderSource::Site->value);
            $table->enum('entry_mode', EntryMode::values())
                ->default(EntryMode::Wizard->value);

            $table->unsignedInteger('coins_charged')->default(0);
            $table->string('coin_txn_ref')->nullable();

            // Regeneration lineage (self references).
            $table->uuid('regenerated_from_order_id')->nullable();
            $table->uuid('root_order_id')->nullable();

            $table->timestamps();
            $table->timestamp('completed_at')->nullable();

            $table->foreign('service_id')
                ->references('id')->on('services')
                ->cascadeOnDelete();

            // Guarantees the chosen version actually belongs to the chosen
            // service: (version, service) must exist as a pair.
            $table->foreign(['service_version_id', 'service_id'])
                ->references(['id', 'service_id'])
                ->on('service_versions')
                ->cascadeOnDelete();

            $table->foreign('regenerated_from_order_id')
                ->references('id')->on('orders')
                ->nullOnDelete();
            $table->foreign('root_order_id')
                ->references('id')->on('orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
