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

        if ($request->filled('regenerated_from_order_id')) {
            $context += $this->resolveRegenerationLineage($request, $service);
        }

        $order = $submit->handle(
            $service,
            (string) $request->userRef(),
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
        abort_unless((string) $order->user_ref === (string) $request->userRef(), 403);

        return OrderResource::make(
            $order->load(['requests.results.file'])
        );
    }

    /**
     * "Run again" chains a new order onto a previous one's regeneration
     * lineage instead of starting a fresh, unrelated one — capped by the
     * service version's regenerate_limit.
     *
     * @return array<string, string>
     */
    private function resolveRegenerationLineage(Request $request, Service $service): array
    {
        $parent = Order::findOrFail($request->validated('regenerated_from_order_id'));
        abort_unless((string) $parent->user_ref === (string) $request->userRef(), 403);
        abort_unless($parent->service_id === $service->id, 422, 'regenerated_from_order_id must belong to the same service.');

        $root = $parent->root_order_id ?? $parent->id;
        $regenerationCount = Order::where('root_order_id', $root)->count();
        $limit = $service->currentVersion?->regenerate_limit ?? 0;

        abort_if($regenerationCount >= $limit, 422, 'Regeneration limit reached for this order.');

        return [
            'regenerated_from_order_id' => $parent->id,
            'root_order_id' => $root,
        ];
    }
}
