<?php

namespace App\Http\Resources;

use App\Models\ServiceWaitingText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceWaitingText
 */
class ServiceWaitingTextResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_version_id' => $this->service_version_id,
            'text' => $this->text,
            'sort_order' => $this->sort_order,
        ];
    }
}
