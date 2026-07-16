<?php

namespace Tests\Feature\Marketplace;

use App\Actions\Catalog\PublishVersion;
use App\Models\Service;
use App\Models\ServiceVersion;
use App\Models\ServiceVote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsCoreUser;
use Tests\TestCase;

class VoteApiTest extends TestCase
{
    use ActsAsCoreUser, RefreshDatabase;

    private function publishedService(): Service
    {
        $service = Service::factory()->create(['vote_up' => 0, 'vote_down' => 0]);
        $version = ServiceVersion::factory()->draft()->create(['service_id' => $service->id, 'version_no' => 1]);
        app(PublishVersion::class)->handle($version);

        return $service->refresh();
    }

    public function test_a_fresh_vote_is_counted_and_flipping_or_repeating_updates_the_cached_counters(): void
    {
        $service = $this->publishedService();
        $headers = $this->coreUserHeaders('user-1');

        $up = $this->withHeaders($headers)->postJson("/api/marketplace/services/{$service->id}/vote", ['value' => 1])->assertOk();
        $this->assertSame(1, $up->json('my_vote'));
        $this->assertSame(1, $up->json('vote_up'));
        $this->assertSame(1, ServiceVote::query()->where('service_id', $service->id)->count());

        // Same direction again removes the vote.
        $removed = $this->withHeaders($headers)->postJson("/api/marketplace/services/{$service->id}/vote", ['value' => 1])->assertOk();
        $this->assertNull($removed->json('my_vote'));
        $this->assertSame(0, $removed->json('vote_up'));
        $this->assertSame(0, ServiceVote::query()->where('service_id', $service->id)->count());

        // Opposite direction flips.
        $this->withHeaders($headers)->postJson("/api/marketplace/services/{$service->id}/vote", ['value' => 1]);
        $flipped = $this->withHeaders($headers)->postJson("/api/marketplace/services/{$service->id}/vote", ['value' => -1])->assertOk();
        $this->assertSame(-1, $flipped->json('my_vote'));
        $this->assertSame(0, $flipped->json('vote_up'));
        $this->assertSame(1, $flipped->json('vote_down'));
        $this->assertSame(1, ServiceVote::query()->where('service_id', $service->id)->count());
    }

    public function test_two_users_voting_the_same_service_both_count(): void
    {
        $service = $this->publishedService();

        $this->withHeaders($this->coreUserHeaders('user-1'))
            ->postJson("/api/marketplace/services/{$service->id}/vote", ['value' => 1])->assertOk();
        $second = $this->withHeaders($this->coreUserHeaders('user-2'))
            ->postJson("/api/marketplace/services/{$service->id}/vote", ['value' => 1])->assertOk();

        $this->assertSame(2, $second->json('vote_up'));
    }

    public function test_a_service_with_no_published_version_cannot_be_voted_on(): void
    {
        $service = Service::factory()->create(['current_version_id' => null]);

        $this->withHeaders($this->coreUserHeaders('user-1'))
            ->postJson("/api/marketplace/services/{$service->id}/vote", ['value' => 1])
            ->assertStatus(422);
    }
}
