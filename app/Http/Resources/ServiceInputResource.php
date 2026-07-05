<?php

namespace App\Http\Resources;

use App\Models\ServiceInput;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceInput
 */
class ServiceInputResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_version_id' => $this->service_version_id,
            'slug' => $this->slug,
            'title' => $this->title,
            'type' => $this->type,
            'required' => $this->required,
            'multi_select' => $this->multi_select,
            'searchable' => $this->searchable,
            'depends_on_input_id' => $this->depends_on_input_id,
            'depends_on_value' => $this->depends_on_value,
            'sort_order' => $this->sort_order,
            'config' => $this->config,
            'options' => ServiceInputOptionResource::collection($this->whenLoaded('options')),
        ];
    }
}
