<?php

namespace Tests\Feature\Marketplace;

use App\Actions\Catalog\PublishVersion;
use App\Enums\ServiceInputType;
use App\Enums\ServiceStatus;
use App\Models\OptionDependency;
use App\Models\Service;
use App\Models\ServiceInput;
use App\Models\ServiceInputOption;
use App\Models\ServiceVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsCoreUser;
use Tests\TestCase;

class CatalogApiTest extends TestCase
{
    use ActsAsCoreUser, RefreshDatabase;

    private function publish(ServiceVersion $version): ServiceVersion
    {
        app(PublishVersion::class)->handle($version);

        return $version->refresh();
    }

    public function test_guest_cannot_browse_the_catalog(): void
    {
        $this->getJson('/api/marketplace/services')->assertUnauthorized();
    }

    public function test_index_lists_only_active_services_with_cached_columns_and_pinned_cost(): void
    {
        $active = Service::factory()->create(['status' => ServiceStatus::Active, 'vote_up' => 12, 'trending_rank' => 1]);
        $this->publish(ServiceVersion::factory()->draft()->create(['service_id' => $active->id, 'version_no' => 1, 'coin_cost' => 3]));

        $paused = Service::factory()->create(['status' => ServiceStatus::Paused]);
        $this->publish(ServiceVersion::factory()->draft()->create(['service_id' => $paused->id, 'version_no' => 1]));

        $response = $this->withHeaders($this->coreUserHeaders('user-1'))
            ->getJson('/api/marketplace/services')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($active->id));
        $this->assertFalse($ids->contains($paused->id));

        $card = collect($response->json('data'))->firstWhere('id', $active->id);
        $this->assertSame(3, $card['coin_cost']);
        $this->assertSame(12, $card['vote_up']);
        $this->assertFalse($card['is_free']);
    }

    public function test_index_filters_by_category_and_saved(): void
    {
        $headers = $this->coreUserHeaders('user-1');

        $interior = Service::factory()->create(['status' => ServiceStatus::Active, 'category' => 'interior']);
        $portrait = Service::factory()->create(['status' => ServiceStatus::Active, 'category' => 'portrait']);

        $this->withHeaders($headers)->postJson("/api/marketplace/services/{$portrait->id}/bookmark")->assertOk();

        $byCategory = $this->withHeaders($headers)->getJson('/api/marketplace/services?category=interior')->assertOk();
        $ids = collect($byCategory->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($interior->id));
        $this->assertFalse($ids->contains($portrait->id));

        $saved = $this->withHeaders($headers)->getJson('/api/marketplace/services?saved=1')->assertOk();
        $savedIds = collect($saved->json('data'))->pluck('id');
        $this->assertTrue($savedIds->contains($portrait->id));
        $this->assertFalse($savedIds->contains($interior->id));
    }

    public function test_show_returns_full_dependency_graph_and_404s_for_inactive(): void
    {
        $service = Service::factory()->create(['status' => ServiceStatus::Active]);
        $version = ServiceVersion::factory()->draft()->create(['service_id' => $service->id, 'version_no' => 1]);

        $roomType = ServiceInput::factory()->ofType(ServiceInputType::Select)->create([
            'service_version_id' => $version->id, 'slug' => 'room_type', 'sort_order' => 1,
        ]);
        $bedroom = ServiceInputOption::factory()->create(['input_id' => $roomType->id, 'slug' => 'bedroom']);

        $style = ServiceInput::factory()->ofType(ServiceInputType::Select)->create([
            'service_version_id' => $version->id, 'slug' => 'style', 'sort_order' => 2,
            'depends_on_input_id' => $roomType->id,
        ]);
        $cozy = ServiceInputOption::factory()->create(['input_id' => $style->id, 'slug' => 'cozy']);
        OptionDependency::factory()->create(['option_id' => $cozy->id, 'parent_option_id' => $bedroom->id]);

        $this->publish($version);

        $response = $this->withHeaders($this->coreUserHeaders('user-1'))
            ->getJson("/api/marketplace/services/{$service->slug}")
            ->assertOk();

        $inputs = collect($response->json('data.version.inputs'));
        $styleInput = $inputs->firstWhere('slug', 'style');
        $this->assertSame($roomType->id, $styleInput['depends_on_input_id']);

        $cozyOption = collect($styleInput['options'])->firstWhere('slug', 'cozy');
        $this->assertSame([$bedroom->id], $cozyOption['parent_option_ids']);

        $paused = Service::factory()->create(['status' => ServiceStatus::Paused]);
        $this->withHeaders($this->coreUserHeaders('user-1'))
            ->getJson("/api/marketplace/services/{$paused->slug}")
            ->assertNotFound();
    }
}
