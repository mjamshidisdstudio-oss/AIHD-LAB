<?php

namespace App\Http\Controllers;

use App\Actions\Storage\StoreMedia;
use App\Enums\FileKind;
use App\Models\File;
use App\Models\Order;
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
    /** 10 MiB upload ceiling. */
    private const MAX_BYTES = 10 * 1024 * 1024;

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

        if (($upload->getSize() ?? 0) > self::MAX_BYTES) {
            return response()->json(['message' => 'Payload too large.'], 413);
        }

        // External services only ever store results here -- an input is
        // something WE upload on the user's behalf, via this same action,
        // from SubmitOrder.
        $file = $storeMedia->handle($order, $upload, FileKind::Result);

        return response()->json(['media_id' => $file->id], 201);
    }
}
