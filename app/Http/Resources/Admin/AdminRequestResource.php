<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Request
 */
class AdminRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attempt_no' => $this->attempt_no,
            'external_order_id' => $this->external_order_id,
            'status' => $this->status,
            'failure_stage' => $this->failure_stage,
            'sent_at' => $this->sent_at,
            'last_polled_at' => $this->last_polled_at,
            'get_poll_count' => $this->get_poll_count,
            'results' => AdminResultResource::collection($this->whenLoaded('results')),
            'webhook_deliveries' => AdminWebhookDeliveryResource::collection($this->whenLoaded('webhookDeliveries')),
        ];
    }
}
