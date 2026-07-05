<?php

namespace App\Http\Resources;

use App\Models\Request as ServiceRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceRequest
 */
class RequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attempt_no' => $this->attempt_no,
            'status' => $this->status,
            'failure_stage' => $this->failure_stage,
            'get_poll_count' => $this->get_poll_count,
            'sent_at' => $this->sent_at,
            'last_polled_at' => $this->last_polled_at,
            'results' => ResultResource::collection($this->whenLoaded('results')),
        ];
    }
}
