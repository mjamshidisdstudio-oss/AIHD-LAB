<?php

namespace App\Events;

use App\Models\Order;
use App\Models\Result;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast once when an order transitions to completed. Streamed on the
 * owner's private channel with a minimal payload — result references only
 * (result_number, type, opaque media_id), never the raw media.
 */
class OrderCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("orders.{$this->order->user_ref}")];
    }

    public function broadcastAs(): string
    {
        return 'order.completed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $results = Result::query()
            ->whereIn('request_id', $this->order->requests()->select('id'))
            ->orderBy('result_number')
            ->get(['result_number', 'type', 'file_id']);

        return [
            'order_id' => $this->order->id,
            'status' => $this->order->status->value,
            'results' => $results->map(fn (Result $r) => [
                'result_number' => $r->result_number,
                'type' => $r->type->value,
                'media_id' => $r->file_id,
            ])->all(),
        ];
    }
}
