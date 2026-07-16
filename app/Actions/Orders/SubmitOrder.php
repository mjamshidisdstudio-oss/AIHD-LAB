<?php

namespace App\Actions\Orders;

use App\Contracts\CoinService;
use App\Enums\EntryMode;
use App\Enums\FileKind;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\RequestStatus;
use App\Enums\ServiceInputType;
use App\Exceptions\Orders\ServiceUnavailableForOrdersException;
use App\Jobs\DispatchRequest;
use App\Models\File;
use App\Models\Order;
use App\Models\Service;
use App\Models\ServiceInput;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Submit an order against a service's currently published version.
 *
 * Ordering is deliberate and load-bearing:
 *   1. coins are deducted BEFORE the DB transaction (the coin service is a
 *      separate system that cannot enlist in our transaction);
 *   2. the order, its inputs, and a queued request are written in ONE
 *      transaction;
 *   3. the dispatch job is enqueued AFTER the transaction commits — never
 *      inside it, or a rollback would leave a ghost job pointing at a row that
 *      does not exist;
 *   4. if the transaction fails, the deduct is refunded (compensating action);
 *      settle() happens later, once the order actually completes.
 *
 * Admin-preview orders (source=admin_preview) are coin-free: no deduct is
 * ever made, so there is nothing to settle or refund for them either.
 */
class SubmitOrder
{
    public function __construct(private CoinService $coins) {}

    /**
     * @param  array<string, mixed>  $answers  input slug => scalar value or option slug(s)
     * @param  array<string, UploadedFile>  $files  input slug => uploaded file
     * @param  array<string, mixed>  $context  source / entry_mode
     */
    public function handle(Service $service, string $userRef, array $answers = [], array $files = [], array $context = []): Order
    {
        $version = $service->currentVersion;

        if ($version === null || ! $version->isPublished()) {
            throw ServiceUnavailableForOrdersException::for($service);
        }

        $version->loadMissing('inputs.options');

        // Pre-generate the order id so it is the idempotency key for the deduct.
        $orderId = (string) Str::orderedUuid();
        $source = $context['source'] ?? OrderSource::Site;
        $isAdminPreview = $source === OrderSource::AdminPreview;
        $coinCost = $isAdminPreview ? 0 : $version->coin_cost;

        // (1) Deduct OUTSIDE the transaction. Admin previews never charge.
        $txnRef = $isAdminPreview ? null : $this->coins->deduct($userRef, $coinCost, $orderId);

        try {
            $order = DB::transaction(function () use ($orderId, $service, $version, $userRef, $coinCost, $txnRef, $source, $answers, $files, $context) {
                $order = Order::create([
                    'id' => $orderId,
                    'user_ref' => $userRef,
                    'service_id' => $service->id,
                    'service_version_id' => $version->id,
                    'status' => OrderStatus::Processing,
                    'source' => $source,
                    'entry_mode' => $context['entry_mode'] ?? EntryMode::Wizard,
                    'coins_charged' => $coinCost,
                    'coin_txn_ref' => $txnRef,
                ]);

                foreach ($version->inputs as $input) {
                    $this->persistAnswer($order, $input, $answers, $files);
                }

                $order->requests()->create([
                    'attempt_no' => 1,
                    'status' => RequestStatus::Queued,
                ]);

                return $order;
            });
        } catch (\Throwable $e) {
            // (4) Compensate the out-of-transaction deduct, if one was made.
            if ($txnRef !== null) {
                $this->coins->refund($txnRef);
            }

            throw $e;
        }

        // (3) Dispatch AFTER commit.
        DispatchRequest::dispatch($order->requests()->where('attempt_no', 1)->firstOrFail());

        return $order;
    }

    /**
     * @param  array<string, mixed>  $answers
     * @param  array<string, UploadedFile>  $files
     */
    private function persistAnswer(Order $order, ServiceInput $input, array $answers, array $files): void
    {
        $answer = $answers[$input->slug] ?? null;
        $file = $files[$input->slug] ?? null;

        $hasAnswer = $answer !== null && $answer !== '' && $answer !== [];
        if ($input->required && ! $hasAnswer && ! $file instanceof UploadedFile) {
            throw ValidationException::withMessages([
                "answers.{$input->slug}" => "The {$input->slug} input is required.",
            ]);
        }

        match ($input->type) {
            ServiceInputType::Text => $this->persistScalar($order, $input, valueText: $hasAnswer ? (string) $answer : null),
            ServiceInputType::Boolean => $this->persistScalar($order, $input, valueBool: $hasAnswer ? filter_var($answer, FILTER_VALIDATE_BOOL) : null),
            ServiceInputType::Select => $this->persistSelect($order, $input, $answer),
            ServiceInputType::Image, ServiceInputType::Video => $this->persistFile($order, $input, $file),
            default => $this->persistScalar($order, $input), // container inputs hold no scalar
        };
    }

    private function persistScalar(Order $order, ServiceInput $input, ?string $valueText = null, ?bool $valueBool = null): void
    {
        if ($valueText === null && $valueBool === null && ! $input->required) {
            // Optional scalar with no answer: nothing to record.
            return;
        }

        $order->inputs()->create([
            'input_id' => $input->id,
            'value_text' => $valueText,
            'value_bool' => $valueBool,
        ]);
    }

    private function persistSelect(Order $order, ServiceInput $input, mixed $answer): void
    {
        $slugs = array_filter((array) $answer, fn ($v) => $v !== null && $v !== '');
        if ($slugs === []) {
            return;
        }

        $orderInput = $order->inputs()->create(['input_id' => $input->id]);

        $optionIds = $input->options
            ->whereIn('slug', $slugs)
            ->pluck('id');

        foreach ($optionIds as $optionId) {
            $orderInput->options()->create(['option_id' => $optionId]);
        }
    }

    private function persistFile(Order $order, ServiceInput $input, ?UploadedFile $file): void
    {
        if (! $file instanceof UploadedFile) {
            return;
        }

        $path = Storage::disk('media')->putFile("inputs/{$order->id}", $file);

        $fileModel = File::create([
            'kind' => FileKind::Input,
            'disk' => 'media',
            'order_id' => $order->id,
            'mime' => $file->getMimeType() ?? 'application/octet-stream',
            'path' => $path,
            'size' => $file->getSize() ?? 0,
        ]);

        $orderInput = $order->inputs()->create(['input_id' => $input->id]);
        $orderInput->files()->create(['file_id' => $fileModel->id, 'position' => 0]);
    }
}
