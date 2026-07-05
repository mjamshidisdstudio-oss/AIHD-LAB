<?php

namespace App\Http\Resources;

use App\Models\ServiceOutput;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceOutput
 */
class ServiceOutputResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_version_id' => $this->service_version_id,
            'result_number' => $this->result_number,
            'type' => $this->type,
        ];
    }
}
