<?php

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\CreateDraftVersion;
use App\Actions\Catalog\PublishVersion;
use App\Actions\Catalog\RetireVersion;
use App\Enums\ServiceStatus;
use App\Enums\ServiceVersionStatus;
use App\Exceptions\Catalog\VersionNotPublishableException;
use App\Models\Service;
use App\Models\ServiceVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublishVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_sets_version_published_and_points_service_current_version(): void
    {
        $service = Service::factory()->create(['current_version_id' => null]);
        $version = ServiceVersion::factory()->draft()->create(['service_id' => $service->id, 'version_no' => 1]);

        $published = app(PublishVersion::class)->handle($version);

        $this->assertSame(ServiceVersionStatus::Published, $published->status);
        $this->assertNotNull($published->published_at);
        $this->assertSame($version->id, $service->refresh()->current_version_id);
    }

    public function test_publish_retires_the_previously_published_version(): void
    {
        $service = Service::factory()->create();
        $v1 = ServiceVersion::factory()->draft()->create(['service_id' => $service->id, 'version_no' => 1]);
        $v2 = ServiceVersion::factory()->draft()->create(['service_id' => $service->id, 'version_no' => 2]);

        app(PublishVersion::class)->handle($v1);
        app(PublishVersion::class)->handle($v2);

        $this->assertSame(ServiceVersionStatus::Retired, $v1->refresh()->status);
        $this->assertSame(ServiceVersionStatus::Published, $v2->refresh()->status);
        $this->assertSame($v2->id, $service->refresh()->current_version_id);
    }

    public function test_publish_resets_failures_and_clears_auto_disabled_state(): void
    {
        $service = Service::factory()->autoDisabled()->create();
        $version = ServiceVersion::factory()->draft()->create(['service_id' => $service->id, 'version_no' => 1]);

        app(PublishVersion::class)->handle($version);

        $service->refresh();
        $this->assertSame(0, $service->consecutive_failures);
        $this->assertSame(ServiceStatus::Active, $service->status);
    }

    public function test_publish_does_not_unpause_an_operator_paused_service(): void
    {
        $service = Service::factory()->paused()->create();
        $version = ServiceVersion::factory()->draft()->create(['service_id' => $service->id, 'version_no' => 1]);

        app(PublishVersion::class)->handle($version);

        $this->assertSame(ServiceStatus::Paused, $service->refresh()->status);
    }

    public function test_only_a_draft_version_can_be_published(): void
    {
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->retired()->create(['service_id' => $service->id, 'version_no' => 1]);

        $this->expectException(VersionNotPublishableException::class);

        app(PublishVersion::class)->handle($version);
    }

    /**
     * Never Again: the "one published version per service" invariant is enforced
     * by the database, not just by PublishVersion retiring the old one.
     */
    public function test_a_service_cannot_have_two_published_versions_at_the_db_level(): void
    {
        $service = Service::factory()->create();
        ServiceVersion::factory()->published()->create(['service_id' => $service->id, 'version_no' => 1]);

        $this->expectException(QueryException::class);

        ServiceVersion::factory()->published()->create(['service_id' => $service->id, 'version_no' => 2]);
    }

    public function test_two_different_services_can_each_have_a_published_version(): void
    {
        $a = ServiceVersion::factory()->published()->create();
        $b = ServiceVersion::factory()->published()->create();

        $this->assertSame(ServiceVersionStatus::Published, $a->status);
        $this->assertSame(ServiceVersionStatus::Published, $b->status);
        $this->assertNotSame($a->service_id, $b->service_id);
    }

    public function test_retire_clears_current_version_when_it_was_current(): void
    {
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->draft()->create(['service_id' => $service->id, 'version_no' => 1]);
        app(PublishVersion::class)->handle($version);
        $this->assertSame($version->id, $service->refresh()->current_version_id);

        app(RetireVersion::class)->handle($version);

        $this->assertSame(ServiceVersionStatus::Retired, $version->refresh()->status);
        $this->assertNull($service->refresh()->current_version_id);
    }

    public function test_create_draft_version_increments_the_version_number(): void
    {
        $service = Service::factory()->create();
        ServiceVersion::factory()->create(['service_id' => $service->id, 'version_no' => 1]);
        ServiceVersion::factory()->create(['service_id' => $service->id, 'version_no' => 2]);

        $draft = app(CreateDraftVersion::class)->handle($service);

        $this->assertSame(3, $draft->version_no);
        $this->assertTrue($draft->isDraft());
    }
}
