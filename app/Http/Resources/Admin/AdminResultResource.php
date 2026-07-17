<?php

namespace App\Http\Resources\Admin;

use App\Enums\InteractionKind;
use App\Models\Interaction;
use App\Models\Result;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Result
 */
class AdminResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'result_number' => $this->result_number,
            'type' => $this->type,
            'source' => $this->source,
            'latency_ms' => $this->latency_ms,
            'received_at' => $this->received_at,
            'file_id' => $this->file_id,
            'text_value' => $this->text_value,
            'download_count' => Interaction::query()
                ->where('kind', InteractionKind::Download)
                ->where('result_id', $this->id)
                ->count(),
        ];
    }
}
