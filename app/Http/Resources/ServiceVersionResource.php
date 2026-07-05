<?php

namespace App\Http\Resources;

use App\Models\ServiceVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceVersion
 */
class ServiceVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_id' => $this->service_id,
            'version_no' => $this->version_no,
            'status' => $this->status,
            'coin_cost' => $this->coin_cost,
            'regenerate_limit' => $this->regenerate_limit,
            'response_timeout_s' => $this->response_timeout_s,
            'get_interval_s' => $this->get_interval_s,
            'max_get_attempts' => $this->max_get_attempts,
            'post_url' => $this->post_url,
            'get_url' => $this->get_url,
            'published_at' => $this->published_at,
            'inputs' => ServiceInputResource::collection($this->whenLoaded('inputs')),
            'outputs' => ServiceOutputResource::collection($this->whenLoaded('outputs')),
            'waiting_texts' => ServiceWaitingTextResource::collection($this->whenLoaded('waitingTexts')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
