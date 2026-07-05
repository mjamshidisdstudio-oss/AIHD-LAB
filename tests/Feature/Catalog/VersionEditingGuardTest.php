<?php

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\PublishVersion;
use App\Enums\ServiceInputType;
use App\Exceptions\Catalog\VersionNotEditableException;
use App\Models\OptionDependency;
use App\Models\Service;
use App\Models\ServiceInput;
use App\Models\ServiceInputOption;
use App\Models\ServiceOutput;
use App\Models\ServiceVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Never Again: a published (or retired) version is frozen. Its inputs, options,
 * outputs, waiting texts, and option dependencies cannot be created, updated, or
 * deleted — you must duplicate it to a new draft first. Enforced at the model
 * layer so no code path can bypass it.
 */
class VersionEditingGuardTest extends TestCase
{
    use RefreshDatabase;

    private function publishedVersionWithInput(): array
    {
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->draft()->create(['service_id' => $service->id, 'version_no' => 1]);
        $input = ServiceInput::factory()->ofType(ServiceInputType::Select)->create([
            'service_version_id' => $version->id,
        ]);
        $option = ServiceInputOption::factory()->create(['input_id' => $input->id]);

        app(PublishVersion::class)->handle($version);

        return [$version->refresh(), $input->refresh(), $option->refresh()];
    }

    public function test_cannot_add_an_input_to_a_published_version(): void
    {
        [$version] = $this->publishedVersionWithInput();

        $this->expectException(VersionNotEditableException::class);

        ServiceInput::factory()->create(['service_version_id' => $version->id]);
    }

    public function test_cannot_update_an_input_of_a_published_version(): void
    {
        [, $input] = $this->publishedVersionWithInput();

        $this->expectException(VersionNotEditableException::class);

        $input->update(['title' => 'changed after publish']);
    }

    public function test_cannot_delete_an_input_of_a_published_version(): void
    {
        [, $input] = $this->publishedVersionWithInput();

        $this->expectException(VersionNotEditableException::class);

        $input->delete();
    }

    public function test_cannot_add_an_option_to_a_published_versions_input(): void
    {
        [, $input] = $this->publishedVersionWithInput();

        $this->expectException(VersionNotEditableException::class);

        ServiceInputOption::factory()->create(['input_id' => $input->id]);
    }

    public function test_cannot_add_an_output_to_a_published_version(): void
    {
        [$version] = $this->publishedVersionWithInput();

        $this->expectException(VersionNotEditableException::class);

        ServiceOutput::factory()->create(['service_version_id' => $version->id]);
    }

    public function test_cannot_add_a_dependency_to_a_published_versions_options(): void
    {
        [, , $option] = $this->publishedVersionWithInput();

        // A dependency whose child option belongs to the published version is
        // rejected (the parent option here lives on an unrelated draft version).
        $parent = ServiceInputOption::factory()->create();

        $this->expectException(VersionNotEditableException::class);

        OptionDependency::factory()->create([
            'option_id' => $option->id,
            'parent_option_id' => $parent->id,
        ]);
    }

    public function test_a_draft_version_is_freely_editable(): void
    {
        $version = ServiceVersion::factory()->draft()->create();
        $input = ServiceInput::factory()->ofType(ServiceInputType::Select)->create([
            'service_version_id' => $version->id,
        ]);

        // No exception: draft content can be changed.
        $input->update(['title' => 'edited while draft']);
        $option = ServiceInputOption::factory()->create(['input_id' => $input->id]);

        $this->assertSame('edited while draft', $input->refresh()->title);
        $this->assertDatabaseHas('service_input_options', ['id' => $option->id]);
    }
}
