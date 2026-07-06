<?php

namespace Tests\Feature\Orders;

use App\Enums\RequestStatus;
use App\Models\Order;
use App\Models\Request;
use App\Models\Result;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_submit_or_read_orders(): void
    {
        $this->postJson('/api/orders', [])->assertUnauthorized();
        $this->getJson('/api/orders/'.fake()->uuid())->assertUnauthorized();
    }

    public function test_a_user_cannot_read_another_users_order(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $othersOrder = Order::factory()->create(); // random user_ref

        $this->getJson("/api/orders/{$othersOrder->id}")->assertForbidden();
    }

    /**
     * The order status endpoint must be answered entirely from our database and
     * must never reach out to the external/dev service.
     */
    public function test_reading_an_order_makes_no_outbound_http(): void
    {
        Http::fake(); // any outbound call would be recorded and fail the assertion

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $order = Order::factory()->create(['user_ref' => $user->id]);
        $request = Request::factory()->create([
            'order_id' => $order->id,
            'status' => RequestStatus::Completed,
        ]);
        Result::factory()->create(['request_id' => $request->id, 'result_number' => 1]);

        $this->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonCount(1, 'data.requests');

        Http::assertNothingSent();
    }
}
