<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreWaitingTextRequest;
use App\Http\Requests\Catalog\UpdateWaitingTextRequest;
use App\Http\Resources\ServiceWaitingTextResource;
use App\Models\ServiceVersion;
use App\Models\ServiceWaitingText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class WaitingTextController extends Controller
{
    public function store(StoreWaitingTextRequest $request, ServiceVersion $version): JsonResponse
    {
        $version->ensureEditable();

        $waitingText = $version->waitingTexts()->create($request->validated());

        return ServiceWaitingTextResource::make($waitingText)
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateWaitingTextRequest $request, ServiceWaitingText $waitingText): ServiceWaitingTextResource
    {
        $waitingText->version->ensureEditable();
        $waitingText->update($request->validated());

        return ServiceWaitingTextResource::make($waitingText->refresh());
    }

    public function destroy(ServiceWaitingText $waitingText): Response
    {
        $waitingText->version->ensureEditable();
        $waitingText->delete();

        return response()->noContent();
    }
}
