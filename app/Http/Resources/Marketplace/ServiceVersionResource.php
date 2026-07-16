<?php

namespace App\Http\Resources\Marketplace;

use App\Http\Resources\ServiceInputResource;
use App\Http\Resources\ServiceOutputResource;
use App\Http\Resources\ServiceWaitingTextResource;
use App\Models\ServiceVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The published version as the marketplace client needs it. Deliberately
 * narrower than the admin ServiceVersionResource: post_url/get_url and the
 * dispatch-tuning fields (response_timeout_s/get_interval_s/max_get_attempts)
 * are internal wiring to the external provider and are never sent to
 * end-customers.
 *
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
            'coin_cost' => $this->coin_cost,
            'regenerate_limit' => $this->regenerate_limit,
            'published_at' => $this->published_at,
            'inputs' => ServiceInputResource::collection($this->whenLoaded('inputs')),
            'outputs' => ServiceOutputResource::collection($this->whenLoaded('outputs')),
            'waiting_texts' => ServiceWaitingTextResource::collection($this->whenLoaded('waitingTexts')),
        ];
    }
}
