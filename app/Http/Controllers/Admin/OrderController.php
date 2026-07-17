<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminOrderListItemResource;
use App\Http\Resources\Admin\AdminOrderResource;
use App\Models\Order;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The admin Orders & Logs tab: a per-service, filterable order list (by
 * source and entry_mode, per the two filters the marketplace review found
 * missing) plus a full per-order drill-down.
 */
class OrderController extends Controller
{
    public function index(Request $request, Service $service): AnonymousResourceCollection
    {
        $query = Order::query()->where('service_id', $service->id);

        if ($request->filled('source')) {
            $query->where('source', $request->query('source'));
        }

        if ($request->filled('entry_mode')) {
            $query->where('entry_mode', $request->query('entry_mode'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $orders = $query->latest()->paginate(20)->withQueryString();

        // Stat chips always reflect the whole service, not the active filter.
        return AdminOrderListItemResource::collection($orders)->additional([
            'meta_stats' => [
                'total' => Order::where('service_id', $service->id)->count(),
                'completed' => Order::where('service_id', $service->id)->where('status', OrderStatus::Completed)->count(),
                'failed' => Order::where('service_id', $service->id)->where('status', OrderStatus::Failed)->count(),
            ],
        ]);
    }

    public function show(Order $order): AdminOrderResource
    {
        $order->load([
            'version.outputs',
            'requests.results',
            'requests.webhookDeliveries',
            'inputs.input',
            'inputs.selectedOptions',
            'inputs.attachedFiles',
        ]);

        return AdminOrderResource::make($order);
    }
}
