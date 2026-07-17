<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Actions\Catalog\CreateDraftVersion;
use App\Actions\Catalog\DuplicateVersion;
use App\Actions\Catalog\PublishVersion;
use App\Actions\Catalog\RetireVersion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreVersionRequest;
use App\Http\Requests\Catalog\UpdateVersionLabelRequest;
use App\Http\Requests\Catalog\UpdateVersionRequest;
use App\Http\Resources\ServiceVersionResource;
use App\Models\Service;
use App\Models\ServiceVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class VersionController extends Controller
{
    public function index(Service $service): AnonymousResourceCollection
    {
        return ServiceVersionResource::collection(
            $service->versions()->orderBy('version_no')->get()
        );
    }

    public function store(StoreVersionRequest $request, Service $service, CreateDraftVersion $action): JsonResponse
    {
        $version = $action->handle($service, $request->validated());

        return ServiceVersionResource::make($version)
            ->response()
            ->setStatusCode(201);
    }

    public function show(ServiceVersion $version): ServiceVersionResource
    {
        return ServiceVersionResource::make(
            $version->load(['inputs.options', 'outputs', 'waitingTexts'])
        );
    }

    public function update(UpdateVersionRequest $request, ServiceVersion $version): ServiceVersionResource
    {
        // A published/retired version's configuration is frozen.
        $version->ensureEditable();
        $version->update($request->validated());

        return ServiceVersionResource::make($version->refresh());
    }

    /**
     * A version's label is bookkeeping metadata, not frozen configuration --
     * renaming works regardless of draft/published/retired status, unlike
     * update() above which guards on ensureEditable().
     */
    public function updateLabel(UpdateVersionLabelRequest $request, ServiceVersion $version): ServiceVersionResource
    {
        $version->update($request->validated());

        return ServiceVersionResource::make($version->refresh());
    }

    public function destroy(ServiceVersion $version): Response
    {
        $version->ensureEditable();
        $version->delete();

        return response()->noContent();
    }

    public function duplicate(ServiceVersion $version, DuplicateVersion $action): JsonResponse
    {
        $new = $action->handle($version);

        return ServiceVersionResource::make(
            $new->load(['inputs.options', 'outputs', 'waitingTexts'])
        )->response()->setStatusCode(201);
    }

    public function publish(ServiceVersion $version, PublishVersion $action): ServiceVersionResource
    {
        return ServiceVersionResource::make($action->handle($version));
    }

    public function retire(ServiceVersion $version, RetireVersion $action): ServiceVersionResource
    {
        return ServiceVersionResource::make($action->handle($version));
    }
}
