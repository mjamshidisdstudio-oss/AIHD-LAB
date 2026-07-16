<?php

namespace App\Http\Resources\Admin;

use App\Models\WebhookDelivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WebhookDelivery
 */
class AdminWebhookDeliveryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_id' => $this->service_id,
            'request_id' => $this->request_id,
            'external_order_id' => $this->external_order_id,
            'result_number' => $this->result_number,
            'outcome' => $this->outcome,
            'http_status' => $this->http_status,
            'raw_body' => $this->raw_body,
            'received_at' => $this->received_at,
        ];
    }
}
