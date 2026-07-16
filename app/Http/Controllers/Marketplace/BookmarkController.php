<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Bookmark;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Toggle a bookmark: create if absent, delete if present. Powers the
 * marketplace's "Saved" filter (CatalogController::index ?saved=1).
 */
class BookmarkController extends Controller
{
    public function store(Request $request, Service $service): JsonResponse
    {
        $userRef = (string) $request->userRef();

        $existing = Bookmark::query()
            ->where('service_id', $service->id)
            ->where('user_ref', $userRef)
            ->first();

        if ($existing !== null) {
            $existing->delete();

            return response()->json(['bookmarked' => false]);
        }

        Bookmark::create([
            'service_id' => $service->id,
            'user_ref' => $userRef,
            'created_at' => now(),
        ]);

        return response()->json(['bookmarked' => true]);
    }
}
