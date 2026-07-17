<?php

namespace Tests\Feature\Admin;

use App\Contracts\CoinService;
use App\Enums\ServiceInputType;
use App\Enums\ServiceOutputType;
use App\Models\Service;
use App\Models\ServiceInput;
use App\Models\ServiceOutput;
use App\Models\ServiceVersion;
use App\Models\User;
use App\Services\Coins\MockCoinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPreviewOrderApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_guest_cannot_submit_a_preview_order(): void
    {
        $version = ServiceVersion::factory()->draft()->create();

        $this->postJson("/api/admin/versions/{$version->id}/preview-orders", [])->assertUnauthorized();
    }

    public function test_admin_can_run_a_preview_order_against_a_draft_version_coin_free(): void
    {
        $this->actingAsAdmin();
        $this->app->instance(CoinService::class, new MockCoinService);
        Queue::fake();

        $service = Service::factory()->create();
        $draft = ServiceVersion::factory()->draft()->create(['service_id' => $service->id, 'version_no' => 1, 'coin_cost' => 5]);
        ServiceInput::factory()->ofType(ServiceInputType::Text)->create([
            'service_version_id' => $draft->id,
            'slug' => 'prompt',
        ]);
        ServiceOutput::factory()->create([
            'service_version_id' => $draft->id,
            'result_number' => 1,
            'type' => ServiceOutputType::Text,
        ]);

        $response = $this->postJson("/api/admin/versions/{$draft->id}/preview-orders", [
            'entry_mode' => 'wizard',
            'answers' => ['prompt' => 'preview this draft'],
        ])->assertStatus(202);

        $response->assertJsonPath('data.source', 'admin_preview');
        $response->assertJsonPath('data.coins_charged', 0);
        $this->assertDatabaseHas('orders', [
            'id' => $response->json('data.id'),
            'service_version_id' => $draft->id,
            'coins_charged' => 0,
        ]);
    }
}
