<?php

namespace Tests\Feature;

use App\Enums\ServiceInputType;
use App\Models\Bookmark;
use App\Models\File;
use App\Models\Interaction;
use App\Models\OptionDependency;
use App\Models\Order;
use App\Models\OrderInput;
use App\Models\OrderInputFile;
use App\Models\OrderInputOption;
use App\Models\Request;
use App\Models\Result;
use App\Models\Service;
use App\Models\ServiceComment;
use App\Models\ServiceInput;
use App\Models\ServiceInputOption;
use App\Models\ServiceOutput;
use App\Models\ServiceVersion;
use App\Models\ServiceVote;
use App\Models\ServiceWaitingText;
use App\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Builds one fully-connected instance of the domain graph, then eager-loads
 * every relationship on every model. A misdefined relation (wrong key/table)
 * would raise a QueryException here, so this asserts the whole model layer is
 * eager-loadable — one of the task's exit criteria.
 */
class EagerLoadingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{class: class-string, id: string, relations: array<int, string>}>
     */
    private function buildGraph(): array
    {
        $service = Service::factory()->create();
        $version = ServiceVersion::factory()->create(['service_id' => $service->id]);
        $service->update(['current_version_id' => $version->id]);

        $roomType = ServiceInput::factory()->ofType(ServiceInputType::Select)->create([
            'service_version_id' => $version->id,
            'slug' => 'room_type',
        ]);
        $style = ServiceInput::factory()->ofType(ServiceInputType::Select)->create([
            'service_version_id' => $version->id,
            'slug' => 'style',
            'depends_on_input_id' => $roomType->id,
        ]);

        $roomOption = ServiceInputOption::factory()->create(['input_id' => $roomType->id]);
        $styleOption = ServiceInputOption::factory()->create(['input_id' => $style->id]);
        $dependency = OptionDependency::factory()->create([
            'option_id' => $styleOption->id,
            'parent_option_id' => $roomOption->id,
        ]);

        $output = ServiceOutput::factory()->create(['service_version_id' => $version->id]);
        $waitingText = ServiceWaitingText::factory()->create(['service_version_id' => $version->id]);

        $order = Order::factory()->create([
            'service_id' => $service->id,
            'service_version_id' => $version->id,
        ]);
        $regeneration = Order::factory()->create([
            'service_id' => $service->id,
            'service_version_id' => $version->id,
            'regenerated_from_order_id' => $order->id,
            'root_order_id' => $order->id,
        ]);

        $selectAnswer = OrderInput::factory()->empty()->create([
            'order_id' => $order->id,
            'input_id' => $roomType->id,
        ]);
        $imageAnswer = OrderInput::factory()->empty()->create([
            'order_id' => $order->id,
            'input_id' => $style->id,
        ]);

        $orderInputOption = OrderInputOption::factory()->create([
            'order_input_id' => $selectAnswer->id,
            'option_id' => $roomOption->id,
        ]);

        $inputFile = File::factory()->input()->create(['order_id' => $order->id]);
        $resultFile = File::factory()->result()->create(['order_id' => $order->id]);
        $orderInputFile = OrderInputFile::factory()->create([
            'order_input_id' => $imageAnswer->id,
            'file_id' => $inputFile->id,
        ]);

        $request = Request::factory()->create(['order_id' => $order->id]);
        $result = Result::factory()->create([
            'request_id' => $request->id,
            'file_id' => $resultFile->id,
        ]);

        $interaction = Interaction::factory()->create([
            'service_id' => $service->id,
            'order_id' => $order->id,
            'result_id' => $result->id,
        ]);
        $webhook = WebhookDelivery::factory()->create([
            'service_id' => $service->id,
            'request_id' => $request->id,
        ]);

        $vote = ServiceVote::factory()->create([
            'service_id' => $service->id,
            'service_version_id' => $version->id,
        ]);
        $comment = ServiceComment::factory()->create(['service_version_id' => $version->id]);
        $reply = ServiceComment::factory()->create([
            'service_version_id' => $version->id,
            'parent_id' => $comment->id,
        ]);
        $bookmark = Bookmark::factory()->create(['service_id' => $service->id]);

        return [
            'Service' => ['class' => Service::class, 'id' => $service->id, 'relations' => [
                'currentVersion', 'versions', 'orders', 'votes', 'bookmarks', 'interactions', 'webhookDeliveries',
            ]],
            'ServiceVersion' => ['class' => ServiceVersion::class, 'id' => $version->id, 'relations' => [
                'service', 'inputs', 'outputs', 'waitingTexts', 'orders', 'comments', 'votes',
            ]],
            'ServiceInput' => ['class' => ServiceInput::class, 'id' => $style->id, 'relations' => [
                'version', 'options', 'dependsOnInput', 'dependents', 'orderInputs',
            ]],
            'ServiceInputOption' => ['class' => ServiceInputOption::class, 'id' => $roomOption->id, 'relations' => [
                'input', 'parentOptions', 'dependentOptions', 'dependencies', 'orderInputOptions',
            ]],
            'OptionDependency' => ['class' => OptionDependency::class, 'id' => $dependency->id, 'relations' => [
                'option', 'parentOption',
            ]],
            'ServiceOutput' => ['class' => ServiceOutput::class, 'id' => $output->id, 'relations' => ['version']],
            'ServiceWaitingText' => ['class' => ServiceWaitingText::class, 'id' => $waitingText->id, 'relations' => ['version']],
            'Order' => ['class' => Order::class, 'id' => $order->id, 'relations' => [
                'service', 'version', 'inputs', 'files', 'requests', 'interactions',
                'regeneratedFrom', 'rootOrder', 'regenerations',
            ]],
            'OrderInput' => ['class' => OrderInput::class, 'id' => $selectAnswer->id, 'relations' => [
                'order', 'input', 'options', 'files', 'selectedOptions', 'attachedFiles',
            ]],
            'OrderInputOption' => ['class' => OrderInputOption::class, 'id' => $orderInputOption->id, 'relations' => [
                'orderInput', 'option',
            ]],
            'OrderInputFile' => ['class' => OrderInputFile::class, 'id' => $orderInputFile->id, 'relations' => [
                'orderInput', 'file',
            ]],
            'Request' => ['class' => Request::class, 'id' => $request->id, 'relations' => [
                'order', 'results', 'webhookDeliveries',
            ]],
            'Result' => ['class' => Result::class, 'id' => $result->id, 'relations' => [
                'request', 'file', 'interactions',
            ]],
            'File' => ['class' => File::class, 'id' => $resultFile->id, 'relations' => [
                'order', 'orderInputFiles', 'results',
            ]],
            'Interaction' => ['class' => Interaction::class, 'id' => $interaction->id, 'relations' => [
                'service', 'order', 'result',
            ]],
            'WebhookDelivery' => ['class' => WebhookDelivery::class, 'id' => $webhook->id, 'relations' => [
                'service', 'request',
            ]],
            'ServiceVote' => ['class' => ServiceVote::class, 'id' => $vote->id, 'relations' => [
                'service', 'version',
            ]],
            'ServiceComment' => ['class' => ServiceComment::class, 'id' => $reply->id, 'relations' => [
                'version', 'parent', 'replies',
            ]],
            'Bookmark' => ['class' => Bookmark::class, 'id' => $bookmark->id, 'relations' => ['service']],
        ];
    }

    public function test_every_relation_on_every_model_is_eager_loadable(): void
    {
        $graph = $this->buildGraph();

        foreach ($graph as $name => $spec) {
            /** @var Model $model */
            $model = $spec['class']::query()
                ->with($spec['relations'])
                ->findOrFail($spec['id']);

            foreach ($spec['relations'] as $relation) {
                $this->assertTrue(
                    $model->relationLoaded($relation),
                    "{$name}::{$relation} was not eager-loaded",
                );
            }
        }
    }

    public function test_deep_nested_eager_load_from_service_root(): void
    {
        $graph = $this->buildGraph();

        $service = Service::with([
            'currentVersion.inputs.options.parentOptions',
            'currentVersion.inputs.dependsOnInput',
            'currentVersion.outputs',
            'currentVersion.waitingTexts',
            'currentVersion.comments.replies',
            'orders.inputs.selectedOptions',
            'orders.inputs.attachedFiles',
            'orders.requests.results.file',
            'orders.files',
            'orders.regenerations',
            'votes.version',
            'bookmarks',
            'interactions.result',
            'webhookDeliveries.request',
        ])->findOrFail($graph['Service']['id']);

        $this->assertTrue($service->relationLoaded('currentVersion'));
        $this->assertTrue($service->currentVersion->relationLoaded('inputs'));
        $this->assertGreaterThan(0, $service->orders->count());
        $this->assertGreaterThan(0, $service->currentVersion->outputs->count());
    }
}
