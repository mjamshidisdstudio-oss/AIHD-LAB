<?php

namespace App\Http\Controllers\Marketplace;

use App\Enums\InteractionKind;
use App\Enums\ServiceKind;
use App\Http\Controllers\Controller;
use App\Models\Interaction;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Logs the click-through when a user opens an external service's card. An
 * external service has no run flow of its own — this interaction is the only
 * record that the marketplace ever sent someone to it.
 */
class InteractionController extends Controller
{
    public function externalClick(Request $request, Service $service): JsonResponse
    {
        abort_unless($service->kind === ServiceKind::External, 422, 'Not an external service.');

        Interaction::create([
            'kind' => InteractionKind::ExternalClick,
            'user_ref' => (string) $request->userRef(),
            'service_id' => $service->id,
            'created_at' => now(),
        ]);

        return response()->json(['logged' => true], 201);
    }
}
