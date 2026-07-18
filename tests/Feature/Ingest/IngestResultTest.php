<?php

namespace Tests\Feature\Ingest;

use App\Contracts\CoinService;
use App\Enums\FailureStage;
use App\Enums\FileKind;
use App\Enums\OrderStatus;
use App\Enums\RequestStatus;
use App\Enums\ResultSource;
use App\Events\OrderCompleted;
use App\Models\File;
use App\Models\Order;
use App\Models\Result;
use App\Services\Ingest\FailRequest;
use App\Services\Ingest\IngestResult;
use App\Support\External\ExternalResultItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\Concerns\BuildsIngestFixtures;
use Tests\TestCase;

/**
 * IngestResult is the single door both the webhook path and the poll sweep
 * write through. These tests exercise it directly, at the point where the
 * Never-Again guarantees actually live: exactly-once insert under a race,
 * counted (not first-result) completion, and no re-settle/re-broadcast once
 * an order is already terminal — completed OR failed.
 */
class IngestResultTest extends TestCase
{
    use BuildsIngestFixtures, RefreshDatabase;

    /**
     * Never Again: a webhook and the poll sweep both delivering the SAME
     * result_number for the same request — in either order — must produce
     * exactly one row, one settle, and one broadcast. Never a duplicate row,
     * never a double charge, never a duplicate notification.
     */
    public function test_same_result_via_webhook_and_poll_inserts_one_row_one_settle_one_broadcast(): void
    {
        $this->assertRaceYieldsExactlyOnce(webhookFirst: true);
        $this->assertRaceYieldsExactlyOnce(webhookFirst: false);
    }

    private function assertRaceYieldsExactlyOnce(bool $webhookFirst): void
    {
        Event::fake([OrderCompleted::class]);
        ['request' => $request, 'order' => $order] = $this->ingestFixture(
            declaredOutputs: 1,
            orderOverrides: ['coin_txn_ref' => 'txn-race'],
        );

        $coins = Mockery::mock(CoinService::class);
        $coins->shouldReceive('settle')->once()->with('txn-race');
        $coins->shouldNotReceive('refund');

        $item = new ExternalResultItem(resultNumber: 1, type: 'text', text: 'the answer');
        $sources = $webhookFirst
            ? [ResultSource::Webhook, ResultSource::Poll]
            : [ResultSource::Poll, ResultSource::Webhook];

        $ingest = new IngestResult($coins);
        foreach ($sources as $source) {
            $ingest->handle($request, $item, $source, latencyMs: 250);
        }

        $this->assertSame(1, Result::where('request_id', $request->id)->where('result_number', 1)->count());
        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);
        Event::assertDispatchedTimes(OrderCompleted::class, 1);
        // Mockery verifies settle(1) + refund(0) on tearDown, per race ordering.
    }

    /**
     * Never Again: completion is COUNTED against the version's declared
     * outputs, not fired the instant any single result arrives.
     */
    public function test_completion_is_counted_not_fired_on_first_result(): void
    {
        Event::fake([OrderCompleted::class]);
        ['request' => $request, 'order' => $order] = $this->ingestFixture(
            declaredOutputs: 2,
            orderOverrides: ['coin_txn_ref' => 'txn-counted'],
        );

        $coins = Mockery::mock(CoinService::class);
        $coins->shouldReceive('settle')->once()->with('txn-counted');
        $ingest = new IngestResult($coins);

        $ingest->handle($request, new ExternalResultItem(resultNumber: 1, type: 'text', text: 'one'), ResultSource::Poll, 100);

        $this->assertSame(RequestStatus::Awaiting, $request->refresh()->status);
        $this->assertSame(OrderStatus::Processing, $order->refresh()->status);
        Event::assertNotDispatched(OrderCompleted::class);

        $ingest->handle($request, new ExternalResultItem(resultNumber: 2, type: 'text', text: 'two'), ResultSource::Poll, 100);

        $this->assertSame(RequestStatus::Completed, $request->refresh()->status);
        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);
        Event::assertDispatchedTimes(OrderCompleted::class, 1);
        // Mockery verifies settle(1) — only after the second result — on tearDown.
    }

    /**
     * Never Again: a redundant delivery arriving AFTER the order has already
     * completed must not re-settle or re-broadcast.
     */
    public function test_second_webhook_after_completion_does_not_resettle_or_rebroadcast(): void
    {
        Event::fake([OrderCompleted::class]);
        ['request' => $request, 'order' => $order] = $this->ingestFixture(
            declaredOutputs: 1,
            orderOverrides: ['coin_txn_ref' => 'txn-post-complete'],
        );

        $coins = Mockery::mock(CoinService::class);
        $coins->shouldReceive('settle')->once()->with('txn-post-complete');
        $ingest = new IngestResult($coins);

        $item = new ExternalResultItem(resultNumber: 1, type: 'text', text: 'first');
        $ingest->handle($request, $item, ResultSource::Webhook, 100);
        $this->assertSame(OrderStatus::Completed, $order->refresh()->status);

        // The exact same delivery arrives again (retry, or a second observer).
        $outcome = $ingest->handle($request, $item, ResultSource::Webhook, 100);

        $this->assertTrue($outcome->duplicate);
        $this->assertSame(1, Result::where('request_id', $request->id)->count());
        Event::assertDispatchedTimes(OrderCompleted::class, 1);
        // Mockery verifies settle(1) total on tearDown — the second delivery never re-settles.
    }

    /**
     * Never Again: once a request has failed (timeout, via the sweep) and been
     * refunded, a late-arriving webhook for that same request must never
     * resurrect it into "completed" and must never trigger a second refund or
     * a settle. A failed order is exactly as terminal as a completed one.
     */
    public function test_failed_order_refunds_exactly_once_across_webhook_and_sweep(): void
    {
        Event::fake([OrderCompleted::class]);
        ['request' => $request, 'order' => $order] = $this->ingestFixture(
            declaredOutputs: 1,
            orderOverrides: ['coin_txn_ref' => 'txn-failed-race'],
            versionOverrides: ['max_get_attempts' => 0],
        );

        $coins = Mockery::mock(CoinService::class);
        $coins->shouldReceive('refund')->once()->with('txn-failed-race');
        $coins->shouldNotReceive('settle');

        // The sweep times this request out (attempt budget already spent).
        (new FailRequest($coins))->handle($request, FailureStage::Timeout);
        $this->assertSame(OrderStatus::Failed, $order->refresh()->status);

        // A late webhook now delivers the result the sweep gave up waiting for.
        $ingest = new IngestResult($coins);
        $outcome = $ingest->handle(
            $request,
            new ExternalResultItem(resultNumber: 1, type: 'text', text: 'too late'),
            ResultSource::Webhook,
            100,
        );

        // The order stays failed — it is never resurrected into completed.
        $this->assertSame(OrderStatus::Failed, $order->refresh()->status);
        $this->assertFalse($outcome->completedOrder);
        Event::assertNotDispatched(OrderCompleted::class);
        // Mockery verifies refund(1) + settle(0) total on tearDown — the late
        // webhook never triggers a second refund or a settle.
    }

    /**
     * Never Again: a result delivered as a media_id reference (the
     * pre-upload-then-reference path via POST /storage) must be REJECTED, not
     * linked, when the referenced file was uploaded for a DIFFERENT order.
     * Without this check, a provider (malicious or merely buggy) could make
     * one customer's order resolve to another customer's uploaded photo just
     * by naming its file id — media_id is otherwise an opaque, guessable-only
     * -by-brute-force UUID, not a secret, so ownership must be checked, not
     * assumed.
     */
    public function test_media_id_referencing_a_different_orders_file_is_rejected_not_linked(): void
    {
        Event::fake([OrderCompleted::class]);
        ['request' => $request, 'order' => $order] = $this->ingestFixture(declaredOutputs: 1);

        $otherOrder = Order::factory()->create();
        $foreignFile = File::factory()->create(['order_id' => $otherOrder->id, 'kind' => FileKind::Result]);

        $coins = Mockery::mock(CoinService::class);
        $coins->shouldNotReceive('settle');
        $coins->shouldNotReceive('refund');

        $outcome = (new IngestResult($coins))->handle(
            $request,
            new ExternalResultItem(resultNumber: 1, type: 'image', mediaId: $foreignFile->id),
            ResultSource::Webhook,
            100,
        );

        $this->assertTrue($outcome->wasRejected());
        $this->assertFalse($outcome->wasIngested());
        $this->assertSame(0, Result::where('request_id', $request->id)->count());
        $this->assertSame(OrderStatus::Processing, $order->refresh()->status);
        Event::assertNotDispatched(OrderCompleted::class);
        // Mockery verifies settle(0) + refund(0) on tearDown — a rejected
        // reference never touches coins either.
    }

    /**
     * The happy path this security check protects: a media_id uploaded FOR
     * THIS order (the real pre-upload-then-reference flow) links directly to
     * the already-stored file — no bytes are re-written, no new File row is
     * created for it.
     */
    public function test_valid_media_id_reference_links_the_existing_file_without_rewriting_it(): void
    {
        Event::fake([OrderCompleted::class]);
        ['request' => $request, 'order' => $order] = $this->ingestFixture(
            declaredOutputs: 1,
            orderOverrides: ['coin_txn_ref' => 'txn-media-ref'],
        );
        $ownFile = File::factory()->create(['order_id' => $order->id, 'kind' => FileKind::Result]);

        $coins = Mockery::mock(CoinService::class);
        $coins->shouldReceive('settle')->once()->with('txn-media-ref');

        $outcome = (new IngestResult($coins))->handle(
            $request,
            new ExternalResultItem(resultNumber: 1, type: 'image', mediaId: $ownFile->id),
            ResultSource::Webhook,
            100,
        );

        $this->assertTrue($outcome->wasIngested());
        $this->assertSame(1, File::where('order_id', $order->id)->count());
        $this->assertDatabaseHas('results', ['request_id' => $request->id, 'result_number' => 1, 'file_id' => $ownFile->id]);
        Event::assertDispatchedTimes(OrderCompleted::class, 1);
    }
}
