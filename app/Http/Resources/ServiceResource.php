<?php

namespace App\Http\Resources;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Service
 */
class ServiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'image_url' => $this->image_url,
            'kind' => $this->kind,
            'external_url' => $this->external_url,
            'category' => $this->category,
            'status' => $this->status,
            // The raw secret is never serialised — only whether one is set and a
            // short non-reversible fingerprint of it.
            'has_secret' => $this->has_secret,
            'secret_preview' => $this->secret_preview,
            'consecutive_failures' => $this->consecutive_failures,
            'current_version_id' => $this->current_version_id,
            'vote_up' => $this->vote_up,
            'vote_down' => $this->vote_down,
            'avg_latency_ms' => $this->avg_latency_ms,
            'trending_rank' => $this->trending_rank,
            'current_version' => ServiceVersionResource::make($this->whenLoaded('currentVersion')),
            'versions' => ServiceVersionResource::collection($this->whenLoaded('versions')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
