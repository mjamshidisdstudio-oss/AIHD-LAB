<?php

namespace App\Http\Resources\Marketplace;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A marketplace grid/board card. Reads ONLY cached columns on the service
 * itself (vote_up, vote_down, avg_latency_ms, trending_rank) plus the pinned
 * current version's coin_cost — never a join against inputs/outputs/results,
 * so the grid stays cheap regardless of catalog depth.
 *
 * is_bookmarked/my_vote are computed by the controller for the requesting
 * user and set as extra attributes on the model before serialisation.
 *
 * @mixin Service
 */
class ServiceCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $coinCost = $this->currentVersion?->coin_cost;

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'tagline' => $this->description,
            'image_url' => $this->image_url,
            'kind' => $this->kind,
            'external_url' => $this->external_url,
            'category' => $this->category,
            'vote_up' => $this->vote_up,
            'vote_down' => $this->vote_down,
            'avg_latency_ms' => $this->avg_latency_ms,
            'trending_rank' => $this->trending_rank,
            'coin_cost' => $coinCost,
            'is_free' => $coinCost === 0,
            'published_at' => $this->currentVersion?->published_at,
            'is_bookmarked' => (bool) $this->is_bookmarked,
            'my_vote' => $this->my_vote !== null ? (int) $this->my_vote : null,
            'created_at' => $this->created_at,
        ];
    }
}
