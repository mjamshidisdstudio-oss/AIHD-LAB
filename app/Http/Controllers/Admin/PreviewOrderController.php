<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Orders\SubmitOrder;
use App\Enums\EntryMode;
use App\Enums\OrderSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SubmitPreviewOrderRequest;
use App\Http\Resources\Admin\AdminOrderResource;
use App\Models\ServiceVersion;
use Illuminate\Http\JsonResponse;

/**
 * The Builder tab's "Generate with AI" live preview: a real order run with
 * source=admin_preview so it is coin-free, strike-free, and cap-free (see
 * SubmitOrder/FailRequest/DispatchRequest). Scoped to an explicit version
 * (draft or published) rather than the service's currentVersion, since the
 * whole point is to exercise a draft before it is ever published.
 */
class PreviewOrderController extends Controller
{
    public function store(SubmitPreviewOrderRequest $request, ServiceVersion $version, SubmitOrder $submit): JsonResponse
    {
        $context = ['source' => OrderSource::AdminPreview];
        if ($request->filled('entry_mode')) {
            $context['entry_mode'] = $request->enum('entry_mode', EntryMode::class);
        }

        $order = $submit->handle(
            $version->service,
            'admin:'.$request->user()->id,
            $request->input('answers', []),
            $request->file('files', []),
            $context,
            $version,
        );

        $order->load([
            'version.outputs',
            'requests.results',
            'requests.webhookDeliveries',
            'inputs.input',
            'inputs.selectedOptions',
            'inputs.attachedFiles',
        ]);

        return AdminOrderResource::make($order)->response()->setStatusCode(202);
    }
}
