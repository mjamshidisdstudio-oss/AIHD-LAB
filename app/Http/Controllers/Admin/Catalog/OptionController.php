<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreOptionRequest;
use App\Http\Requests\Catalog\UpdateOptionRequest;
use App\Http\Resources\ServiceInputOptionResource;
use App\Models\ServiceInput;
use App\Models\ServiceInputOption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class OptionController extends Controller
{
    public function store(StoreOptionRequest $request, ServiceInput $input): JsonResponse
    {
        $input->version->ensureEditable();

        $option = $input->options()->create($request->validated());

        return ServiceInputOptionResource::make($option)
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateOptionRequest $request, ServiceInputOption $option): ServiceInputOptionResource
    {
        $option->input->version->ensureEditable();
        $option->update($request->validated());

        return ServiceInputOptionResource::make($option->refresh());
    }

    public function destroy(ServiceInputOption $option): Response
    {
        $option->input->version->ensureEditable();
        $option->delete();

        return response()->noContent();
    }
}
