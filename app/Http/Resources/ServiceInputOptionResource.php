<?php

namespace App\Http\Resources;

use App\Models\ServiceInputOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceInputOption
 */
class ServiceInputOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'input_id' => $this->input_id,
            'slug' => $this->slug,
            'label' => $this->label,
            'color' => $this->color,
            'icon' => $this->icon,
            'sort_order' => $this->sort_order,
            'parent_option_ids' => $this->whenLoaded(
                'parentOptions',
                fn () => $this->parentOptions->pluck('id'),
            ),
        ];
    }
}
