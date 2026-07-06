<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_id' => $this->service_id,
            'service_version_id' => $this->service_version_id,
            'status' => $this->status,
            'source' => $this->source,
            'entry_mode' => $this->entry_mode,
            'coins_charged' => $this->coins_charged,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
            'requests' => RequestResource::collection($this->whenLoaded('requests')),
        ];
    }
}
