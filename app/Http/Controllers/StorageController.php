<?php

namespace App\Http\Controllers;

use App\Actions\Storage\StoreMedia;
use App\Enums\FileKind;
use App\Enums\MediaType;
use App\Enums\WebhookOutcome;
use App\Exceptions\Storage\MediaValidationException;
use App\Models\File;
use App\Models\Order;
use App\Models\ServiceOutput;
use App\Models\WebhookDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Opaque media storage for external services. Authenticated by the per-service
 * service_key (Bearer) — NOT user/Sanctum auth, since the dev service has no
 * user session. media_id is files.id; callers never see disk paths or buckets.
 */
class StorageController extends Controller
{
    public function show(Request $request, string $mediaId): StreamedResponse|JsonResponse
    {
        $bearer = $request->bearerToken();
        if ($bearer === null || $bearer === '') {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $file = File::query()->with('order.service')->find($mediaId);
        if ($file === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if (! $file->order->service->verifyServiceKey($bearer)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        if (! Storage::disk($file->disk)->exists($file->path)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return Storage::disk($file->disk)->response(
            $file->path,
            null,
            ['Content-Type' => $file->mime],
        );
    }

    public function store(Request $request, StoreMedia $storeMedia): JsonResponse
    {
        $bearer = $request->bearerToken();
        if ($bearer === null || $bearer === '') {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $order = Order::query()->with('service')->find($request->input('order_id'));
        if ($order === null) {
            return response()->json(['message' => 'Unknown order.'], 422);
        }

        if (! $order->service->verifyServiceKey($bearer)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $upload = $request->file('file');
        if ($upload === null) {
            return response()->json(['message' => 'A file is required.'], 422);
        }

        $resultNumber = $request->input('result_number');
        if (! is_numeric($resultNumber)) {
            return response()->json(['message' => 'result_number is required.'], 422);
        }
        $resultNumber = (int) $resultNumber;

        $output = ServiceOutput::query()
            ->where('service_version_id', $order->service_version_id)
            ->where('result_number', $resultNumber)
            ->first();

        if ($output === null) {
            return response()->json(['message' => 'Unknown result_number for this order.'], 422);
        }

        // External services only ever store results here -- an input is
        // something WE upload on the user's behalf, via this same action,
        // from SubmitOrder. The declared output's own type is the expected
        // media type: this is exactly what catches a service that uploads a
        // video for a result its version declared as an image, or anything
        // whose real content doesn't match what it claims to be.
        try {
            $file = $storeMedia->handle($order, $upload, FileKind::Result, MediaType::from($output->type->value));
        } catch (MediaValidationException $e) {
            $status = $e->tooLarge ? 413 : 422;
            $this->recordRejection($order, $resultNumber, $e, $status);

            return response()->json(['message' => $e->getMessage()], $status);
        }

        return response()->json(['media_id' => $file->id], 201);
    }

    /**
     * A rejected storage upload has no webhook delivery of its own to log
     * against (it happens before any webhook is ever sent) -- logged to the
     * same receipt trail as inbound webhook deliveries instead, against the
     * order's current request, so an operator has one place to look either way.
     */
    private function recordRejection(Order $order, int $resultNumber, MediaValidationException $e, int $status): void
    {
        $request = $order->requests()->orderByDesc('attempt_no')->first();

        WebhookDelivery::create([
            'service_id' => $order->service_id,
            'request_id' => $request?->id,
            'external_order_id' => $request?->external_order_id,
            'result_number' => $resultNumber,
            'outcome' => WebhookOutcome::InvalidMedia,
            'http_status' => $status,
            'raw_body' => json_encode([
                'order_id' => $order->id,
                'result_number' => $resultNumber,
                'expected_type' => $e->expectedType->value,
                'reason' => $e->getMessage(),
            ]),
        ]);
    }
}
