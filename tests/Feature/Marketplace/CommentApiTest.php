<?php

namespace Tests\Feature\Marketplace;

use App\Actions\Catalog\PublishVersion;
use App\Models\Service;
use App\Models\ServiceComment;
use App\Models\ServiceVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsCoreUser;
use Tests\TestCase;

class CommentApiTest extends TestCase
{
    use ActsAsCoreUser, RefreshDatabase;

    private function publishedService(): Service
    {
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->draft()->create(['service_id' => $service->id, 'version_no' => 1]);
        app(PublishVersion::class)->handle($version);

        return $service->refresh();
    }

    public function test_posting_a_comment_and_a_reply_and_listing_them(): void
    {
        $service = $this->publishedService();
        $headers = $this->coreUserHeaders('user-1');

        $root = $this->withHeaders($headers)
            ->postJson("/api/marketplace/services/{$service->id}/comments", ['body' => 'Works great.'])
            ->assertCreated()
            ->json('data');

        $this->withHeaders($this->coreUserHeaders('user-2'))
            ->postJson("/api/marketplace/services/{$service->id}/comments", [
                'body' => 'Thanks!',
                'parent_id' => $root['id'],
            ])->assertCreated();

        $list = $this->withHeaders($headers)
            ->getJson("/api/marketplace/services/{$service->id}/comments")
            ->assertOk();

        $this->assertCount(1, $list->json('data'));
        $this->assertCount(1, $list->json('data.0.replies'));
        $this->assertSame('Thanks!', $list->json('data.0.replies.0.body'));
    }

    public function test_a_hidden_comment_is_not_listed(): void
    {
        $service = $this->publishedService();
        ServiceComment::factory()->hidden()->create(['service_version_id' => $service->current_version_id]);

        $list = $this->withHeaders($this->coreUserHeaders('user-1'))
            ->getJson("/api/marketplace/services/{$service->id}/comments")
            ->assertOk();

        $this->assertCount(0, $list->json('data'));
    }

    public function test_a_reply_cannot_target_a_comment_on_a_different_version(): void
    {
        $service = $this->publishedService();
        $otherVersionComment = ServiceComment::factory()->create();

        $this->withHeaders($this->coreUserHeaders('user-1'))
            ->postJson("/api/marketplace/services/{$service->id}/comments", [
                'body' => 'Hijack attempt',
                'parent_id' => $otherVersionComment->id,
            ])->assertStatus(422);
    }
}
