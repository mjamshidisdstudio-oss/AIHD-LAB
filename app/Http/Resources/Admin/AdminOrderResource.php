<?php

namespace App\Http\Resources\Admin;

use App\Enums\InteractionKind;
use App\Models\Interaction;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full order drill-down for the admin Orders & Logs tab: lineage, every
 * request attempt (with its results and webhook deliveries), every answered
 * input, and the per-output-slot view assembled from the version's declared
 * outputs cross-referenced against the latest attempt that produced results.
 *
 * @mixin Order
 */
class AdminOrderResource extends JsonResource
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
            'version_no' => $this->whenLoaded('version', fn () => $this->version?->version_no),
            'user_ref' => $this->user_ref,
            'status' => $this->status,
            'source' => $this->source,
            'entry_mode' => $this->entry_mode,
            'coins_charged' => $this->coins_charged,
            'coin_txn_ref' => $this->coin_txn_ref,
            'regenerated_from_order_id' => $this->regenerated_from_order_id,
            'root_order_id' => $this->root_order_id,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
            'requests' => AdminRequestResource::collection($this->whenLoaded('requests')),
            'inputs' => AdminOrderInputResource::collection($this->whenLoaded('inputs')),
            'outputs' => $this->whenLoaded('version', fn () => $this->outputsView()),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function outputsView(): array
    {
        $version = $this->version;
        if ($version === null) {
            return [];
        }

        $requests = $this->requests->sortByDesc('attempt_no')->values();
        $latestWithResults = $requests->first(fn ($r) => $r->results->isNotEmpty());
        $latestOverall = $requests->first();

        return $version->outputs->sortBy('result_number')->map(function ($output) use ($latestWithResults, $latestOverall) {
            $result = $latestWithResults?->results->firstWhere('result_number', $output->result_number);

            return [
                'result_number' => $output->result_number,
                'type' => $output->type,
                'has_result' => $result !== null,
                'source' => $result?->source,
                'latency_ms' => $result?->latency_ms,
                'received_at' => $result?->received_at,
                'file_id' => $result?->file_id,
                'text_value' => $result?->text_value,
                'failure_stage' => $result === null ? $latestOverall?->failure_stage : null,
                'download_count' => $result === null ? 0 : Interaction::query()
                    ->where('kind', InteractionKind::Download)
                    ->where('result_id', $result->id)
                    ->count(),
            ];
        })->values()->all();
    }
}
