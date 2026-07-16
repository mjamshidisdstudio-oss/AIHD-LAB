<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceVote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * One flippable vote per (service, user): casting the same direction again
 * removes it, casting the opposite direction flips it. services.vote_up/
 * vote_down are cached counters kept in lockstep inside the same transaction
 * that writes the vote, so the marketplace grid never has to aggregate
 * service_votes to show a count.
 */
class VoteController extends Controller
{
    public function store(Request $request, Service $service): JsonResponse
    {
        abort_if($service->current_version_id === null, 422, 'This service has no published version to vote on.');

        $data = $request->validate([
            'value' => ['required', 'integer', 'in:1,-1'],
        ]);
        $userRef = (string) $request->userRef();
        $requested = (int) $data['value'];

        DB::transaction(function () use ($service, $userRef, $requested): void {
            $service = Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();
            $existing = ServiceVote::query()
                ->where('service_id', $service->id)
                ->where('user_ref', $userRef)
                ->first();

            if ($existing === null) {
                ServiceVote::create([
                    'service_id' => $service->id,
                    'service_version_id' => $service->current_version_id,
                    'user_ref' => $userRef,
                    'value' => $requested,
                ]);
                $service->increment($requested === 1 ? 'vote_up' : 'vote_down');

                return;
            }

            if ($existing->value === $requested) {
                $existing->delete();
                $service->decrement($requested === 1 ? 'vote_up' : 'vote_down');

                return;
            }

            $previousValue = $existing->value;
            $existing->update(['value' => $requested]);
            $service->decrement($previousValue === 1 ? 'vote_up' : 'vote_down');
            $service->increment($requested === 1 ? 'vote_up' : 'vote_down');
        });

        $service->refresh();

        return response()->json([
            'my_vote' => ServiceVote::query()
                ->where('service_id', $service->id)
                ->where('user_ref', $userRef)
                ->value('value'),
            'vote_up' => $service->vote_up,
            'vote_down' => $service->vote_down,
        ]);
    }
}
