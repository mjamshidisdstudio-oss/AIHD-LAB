<?php

namespace Tests\Feature\Marketplace;

use App\Enums\InteractionKind;
use App\Models\File;
use App\Models\Interaction;
use App\Models\Order;
use App\Models\Request;
use App\Models\Result;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsCoreUser;
use Tests\TestCase;

/**
 * The only path a browser ever gets a result's bytes from — proving a raw
 * storage URL is never handed out and every download writes an interaction.
 */
class DownloadApiTest extends TestCase
{
    use ActsAsCoreUser, RefreshDatabase;

    private function resultWithFile(string $ownerRef): Result
    {
        Storage::fake('media');
        $order = Order::factory()->create(['user_ref' => $ownerRef]);
        $request = Request::factory()->create(['order_id' => $order->id]);
        $file = File::factory()->result()->create(['order_id' => $order->id]);
        Storage::disk('media')->put($file->path, 'PIXEL-BYTES');

        return Result::factory()->create(['request_id' => $request->id, 'file_id' => $file->id]);
    }

    public function test_downloading_ones_own_result_logs_an_interaction_and_streams_the_file(): void
    {
        $result = $this->resultWithFile('user-1');

        $this->withHeaders($this->coreUserHeaders('user-1'))
            ->get("/api/marketplace/results/{$result->id}/download")
            ->assertOk();

        $this->assertDatabaseHas('interactions', [
            'result_id' => $result->id,
            'kind' => InteractionKind::Download->value,
            'user_ref' => 'user-1',
        ]);
    }

    public function test_a_user_cannot_download_another_users_result(): void
    {
        $result = $this->resultWithFile('user-1');

        $this->withHeaders($this->coreUserHeaders('user-2'))
            ->get("/api/marketplace/results/{$result->id}/download")
            ->assertForbidden();

        $this->assertSame(0, Interaction::count());
    }

    public function test_a_result_with_no_file_404s_not_500s(): void
    {
        $order = Order::factory()->create(['user_ref' => 'user-1']);
        $request = Request::factory()->create(['order_id' => $order->id]);
        $result = Result::factory()->text()->create(['request_id' => $request->id, 'file_id' => null]);

        $this->withHeaders($this->coreUserHeaders('user-1'))
            ->getJson("/api/marketplace/results/{$result->id}/download")
            ->assertNotFound();
    }
}
