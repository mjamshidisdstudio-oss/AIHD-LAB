<?php

namespace App\Http\Controllers;

use App\Actions\Orders\SubmitOrder;
use App\Enums\EntryMode;
use App\Http\Requests\Orders\SubmitOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function store(SubmitOrderRequest $request, SubmitOrder $submit): JsonResponse
    {
        $service = Service::findOrFail($request->validated('service_id'));

        $context = [];
        if ($request->filled('entry_mode')) {
            $context['entry_mode'] = $request->enum('entry_mode', EntryMode::class);
        }

        $order = $submit->handle(
            $service,
            (string) $request->user()->id,
            $request->input('answers', []),
            $request->file('files', []),
            $context,
        );

        return OrderResource::make($order->load(['requests']))
            ->response()
            ->setStatusCode(202);
    }

    /**
     * Return an order's status and results. Answered entirely from our own
     * database — this endpoint must never call the external/dev service.
     */
    public function show(Request $request, Order $order): OrderResource
    {
        abort_unless((string) $order->user_ref === (string) $request->user()->id, 403);

        return OrderResource::make(
            $order->load(['requests.results.file'])
        );
    }
}
