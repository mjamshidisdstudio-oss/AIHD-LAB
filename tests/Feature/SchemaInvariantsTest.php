<?php

namespace Tests\Feature;

use App\Models\Bookmark;
use App\Models\Order;
use App\Models\OrderInput;
use App\Models\Service;
use App\Models\ServiceInput;
use App\Models\ServiceVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Locks the database-level invariants of the data model. Each `rejects_*` test
 * is a "Never Again" regression guard for a MySQL 8 constraint that Eloquent
 * cannot enforce on its own.
 */
class SchemaInvariantsTest extends TestCase
{
    use RefreshDatabase;

    public function test_models_use_uuid_string_primary_keys(): void
    {
        $service = Service::factory()->create();

        $this->assertFalse($service->getIncrementing());
        $this->assertSame('string', $service->getKeyType());
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $service->getKey(),
        );
    }

    public function test_service_secret_is_stored_hashed(): void
    {
        $service = Service::factory()->create(['service_secret' => 'super-secret-value']);

        $stored = $service->fresh()->getAttributes()['service_secret'];

        $this->assertNotSame('super-secret-value', $stored);
        $this->assertTrue(Hash::check('super-secret-value', $stored));
    }

    public function test_order_input_generated_column_counts_populated_scalar_slots(): void
    {
        $textAnswer = OrderInput::factory()->create(['value_text' => 'hello', 'value_bool' => null]);
        $boolAnswer = OrderInput::factory()->create(['value_text' => null, 'value_bool' => true]);
        $emptyAnswer = OrderInput::factory()->empty()->create();

        $this->assertSame(1, $textAnswer->fresh()->value_fill_count);
        $this->assertSame(1, $boolAnswer->fresh()->value_fill_count);
        $this->assertSame(0, $emptyAnswer->fresh()->value_fill_count);
    }

    /**
     * Never Again: the CHECK constraint on the generated column must forbid an
     * answer that carries both a text value and a boolean value at once.
     */
    public function test_order_input_check_rejects_two_scalar_values(): void
    {
        $this->expectException(QueryException::class);

        OrderInput::factory()->create([
            'value_text' => 'hello',
            'value_bool' => true,
        ]);
    }

    /**
     * Never Again: the composite FK must reject an order whose version belongs
     * to a different service than the order's service_id.
     */
    public function test_order_composite_fk_rejects_version_from_another_service(): void
    {
        $serviceA = Service::factory()->create();
        $versionOfA = ServiceVersion::factory()->create(['service_id' => $serviceA->id]);

        $serviceB = Service::factory()->create();

        $this->expectException(QueryException::class);

        Order::factory()->create([
            'service_id' => $serviceB->id,
            'service_version_id' => $versionOfA->id,
        ]);
    }

    public function test_service_version_number_is_unique_per_service(): void
    {
        $service = Service::factory()->create();
        ServiceVersion::factory()->create(['service_id' => $service->id, 'version_no' => 1]);

        $this->expectException(QueryException::class);

        ServiceVersion::factory()->create(['service_id' => $service->id, 'version_no' => 1]);
    }

    public function test_service_input_slug_is_unique_per_version(): void
    {
        $version = ServiceVersion::factory()->create();
        ServiceInput::factory()->create(['service_version_id' => $version->id, 'slug' => 'room_type']);

        $this->expectException(QueryException::class);

        ServiceInput::factory()->create(['service_version_id' => $version->id, 'slug' => 'room_type']);
    }

    public function test_bookmark_is_unique_per_user_and_service(): void
    {
        $service = Service::factory()->create();
        Bookmark::factory()->create(['service_id' => $service->id, 'user_ref' => 'user-1']);

        $this->expectException(QueryException::class);

        Bookmark::factory()->create(['service_id' => $service->id, 'user_ref' => 'user-1']);
    }
}
