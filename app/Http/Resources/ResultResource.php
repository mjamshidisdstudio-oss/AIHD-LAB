<?php

namespace App\Http\Resources;

use App\Models\Result;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Result
 */
class ResultResource extends JsonResource
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
            'text_value' => $this->text_value,
            'latency_ms' => $this->latency_ms,
            'received_at' => $this->received_at,
            'file' => $this->when($this->file_id !== null && $this->relationLoaded('file'), fn () => [
                'id' => $this->file?->id,
                'mime' => $this->file?->mime,
                'size' => $this->file?->size,
            ]),
        ];
    }
}
