<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminWebhookDeliveryResource;
use App\Models\Service;
use App\Models\WebhookDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The "your webhook didn't fire" lookup: every inbound delivery for a
 * service, including the ones that never resolved to a known order
 * (invalid_signature, unknown_order, validation_error) — so it has to be
 * searchable independently of drilling into a specific order.
 */
class WebhookDeliveryController extends Controller
{
    public function index(Request $request, Service $service): AnonymousResourceCollection
    {
        $query = WebhookDelivery::query()->where('service_id', $service->id);

        if ($request->filled('outcome')) {
            $query->where('outcome', $request->query('outcome'));
        }

        if ($request->filled('external_order_id')) {
            $query->where('external_order_id', 'like', '%'.$request->query('external_order_id').'%');
        }

        $deliveries = $query->latest('received_at')->paginate(20)->withQueryString();

        return AdminWebhookDeliveryResource::collection($deliveries);
    }

    public function show(WebhookDelivery $webhookDelivery): AdminWebhookDeliveryResource
    {
        return AdminWebhookDeliveryResource::make($webhookDelivery);
    }
}
