<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Actions\Catalog\CreateService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreServiceRequest;
use App\Http\Requests\Catalog\UpdateServiceRequest;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ServiceController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ServiceResource::collection(
            Service::query()->latest()->paginate()
        );
    }

    public function store(StoreServiceRequest $request, CreateService $createService): JsonResponse
    {
        $service = $createService->handle($request->validated());

        return ServiceResource::make($service->load('versions'))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Service $service): ServiceResource
    {
        return ServiceResource::make($service->load(['currentVersion', 'versions']));
    }

    public function update(UpdateServiceRequest $request, Service $service): ServiceResource
    {
        $service->update($request->validated());

        return ServiceResource::make($service->refresh());
    }
}
