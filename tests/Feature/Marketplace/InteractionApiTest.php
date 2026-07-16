<?php

namespace Tests\Feature\Marketplace;

use App\Enums\InteractionKind;
use App\Models\Interaction;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsCoreUser;
use Tests\TestCase;

class InteractionApiTest extends TestCase
{
    use ActsAsCoreUser, RefreshDatabase;

    public function test_clicking_an_external_service_logs_an_external_click_interaction(): void
    {
        $service = Service::factory()->external()->create();

        $this->withHeaders($this->coreUserHeaders('user-1'))
            ->postJson("/api/marketplace/services/{$service->id}/external-click")
            ->assertCreated();

        $this->assertDatabaseHas('interactions', [
            'service_id' => $service->id,
            'user_ref' => 'user-1',
            'kind' => InteractionKind::ExternalClick->value,
        ]);
    }

    public function test_an_internal_service_rejects_the_external_click_endpoint(): void
    {
        $service = Service::factory()->create(); // internal by default

        $this->withHeaders($this->coreUserHeaders('user-1'))
            ->postJson("/api/marketplace/services/{$service->id}/external-click")
            ->assertStatus(422);

        $this->assertSame(0, Interaction::count());
    }
}
