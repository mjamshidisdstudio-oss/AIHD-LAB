<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreOptionDependencyRequest;
use App\Http\Requests\Catalog\UpdateOptionDependencyRequest;
use App\Http\Resources\OptionDependencyResource;
use App\Models\OptionDependency;
use App\Models\ServiceVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class OptionDependencyController extends Controller
{
    public function store(StoreOptionDependencyRequest $request, ServiceVersion $version): JsonResponse
    {
        $version->ensureEditable();

        $dependency = OptionDependency::create($request->validated());

        return OptionDependencyResource::make($dependency)
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateOptionDependencyRequest $request, OptionDependency $optionDependency): OptionDependencyResource
    {
        $optionDependency->option->input->version->ensureEditable();
        $optionDependency->update($request->validated());

        return OptionDependencyResource::make($optionDependency->refresh());
    }

    public function destroy(OptionDependency $optionDependency): Response
    {
        $optionDependency->option->input->version->ensureEditable();
        $optionDependency->delete();

        return response()->noContent();
    }
}
