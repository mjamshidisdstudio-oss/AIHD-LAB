<?php

namespace App\Http\Resources\Admin;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A row in the Orders & Logs list — cheap fields only, no nested
 * requests/results (see AdminOrderResource for the drill-down detail).
 *
 * @mixin Order
 */
class AdminOrderListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_ref' => $this->user_ref,
            'status' => $this->status,
            'source' => $this->source,
            'entry_mode' => $this->entry_mode,
            'coins_charged' => $this->coins_charged,
            'regenerated_from_order_id' => $this->regenerated_from_order_id,
            'root_order_id' => $this->root_order_id,
            'created_at' => $this->created_at,
            'completed_at' => $this->completed_at,
        ];
    }
}
