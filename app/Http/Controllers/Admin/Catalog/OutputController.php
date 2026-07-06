<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreOutputRequest;
use App\Http\Requests\Catalog\UpdateOutputRequest;
use App\Http\Resources\ServiceOutputResource;
use App\Models\ServiceOutput;
use App\Models\ServiceVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class OutputController extends Controller
{
    public function store(StoreOutputRequest $request, ServiceVersion $version): JsonResponse
    {
        $version->ensureEditable();

        $output = $version->outputs()->create($request->validated());

        return ServiceOutputResource::make($output)
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateOutputRequest $request, ServiceOutput $output): ServiceOutputResource
    {
        $output->version->ensureEditable();
        $output->update($request->validated());

        return ServiceOutputResource::make($output->refresh());
    }

    public function destroy(ServiceOutput $output): Response
    {
        $output->version->ensureEditable();
        $output->delete();

        return response()->noContent();
    }
}
