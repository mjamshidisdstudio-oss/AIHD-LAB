<?php

namespace App\Http\Controllers\Marketplace;

use App\Enums\InteractionKind;
use App\Http\Controllers\Controller;
use App\Models\Interaction;
use App\Models\Result;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The ONLY way a result's file ever reaches a browser. There is no raw
 * storage URL handed to the client — every download passes through here so
 * an interaction of kind=download is always written first.
 */
class DownloadController extends Controller
{
    public function show(Request $request, Result $result): StreamedResponse|JsonResponse
    {
        $result->loadMissing(['request.order', 'file']);
        $order = $result->request->order;

        abort_unless((string) $order->user_ref === (string) $request->userRef(), 403);

        if ($result->file === null || ! Storage::disk($result->file->disk)->exists($result->file->path)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        Interaction::create([
            'kind' => InteractionKind::Download,
            'user_ref' => (string) $request->userRef(),
            'service_id' => $order->service_id,
            'order_id' => $order->id,
            'result_id' => $result->id,
            'created_at' => now(),
        ]);

        return Storage::disk($result->file->disk)->response(
            $result->file->path,
            null,
            ['Content-Type' => $result->file->mime],
        );
    }
}
