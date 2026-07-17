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
            'tagline' => $this->tagline,
            'image_url' => $this->image_url,
            'gallery' => $this->gallery ?? [],
            'before_image_url' => $this->before_image_url,
            'after_image_url' => $this->after_image_url,
            'kind' => $this->kind,
            'external_url' => $this->external_url,
            'category' => $this->category,
            'status' => $this->status,
            // Neither secret is ever serialised — only whether each is set and a
            // short non-reversible fingerprint of it.
            'has_secret' => $this->has_secret,
            'secret_preview' => $this->secret_preview,
            'has_webhook_signing_key' => $this->has_webhook_signing_key,
            'webhook_signing_key_preview' => $this->webhook_signing_key_preview,
            'consecutive_failures' => $this->consecutive_failures,
            'current_version_id' => $this->current_version_id,
            'vote_up' => $this->vote_up,
            'vote_down' => $this->vote_down,
            'avg_latency_ms' => $this->avg_latency_ms,
            'trending_rank' => $this->trending_rank,
            'current_version' => ServiceVersionResource::make($this->whenLoaded('currentVersion')),
            'versions' => ServiceVersionResource::collection($this->whenLoaded('versions')),
            // Cheap summary counts for the admin List screen's cards — only
            // present when the controller opted into loading them.
            'versions_count' => $this->when(isset($this->versions_count), fn () => (int) $this->versions_count),
            'item_count' => $this->whenLoaded('currentVersion', fn () => $this->currentVersion?->inputs?->count() ?? 0),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
