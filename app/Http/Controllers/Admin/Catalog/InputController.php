<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreInputRequest;
use App\Http\Requests\Catalog\UpdateInputRequest;
use App\Http\Resources\ServiceInputResource;
use App\Models\ServiceInput;
use App\Models\ServiceVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class InputController extends Controller
{
    public function store(StoreInputRequest $request, ServiceVersion $version): JsonResponse
    {
        $version->ensureEditable();

        $input = $version->inputs()->create($request->validated());

        return ServiceInputResource::make($input)
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateInputRequest $request, ServiceInput $input): ServiceInputResource
    {
        $input->version->ensureEditable();
        $input->update($request->validated());

        return ServiceInputResource::make($input->refresh());
    }

    public function destroy(ServiceInput $input): Response
    {
        $input->version->ensureEditable();
        $input->delete();

        return response()->noContent();
    }
}
