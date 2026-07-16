<?php

namespace App\Http\Resources\Admin;

use App\Models\OrderInput;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrderInput
 */
class AdminOrderInputResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'input_id' => $this->input_id,
            'input_title' => $this->input?->title,
            'input_slug' => $this->input?->slug,
            'input_type' => $this->input?->type,
            'value_text' => $this->value_text,
            'value_bool' => $this->value_bool,
            'selected_options' => $this->whenLoaded(
                'selectedOptions',
                fn () => $this->selectedOptions->map(fn ($option) => [
                    'id' => $option->id,
                    'label' => $option->label,
                    'slug' => $option->slug,
                ])->values(),
            ),
            'files' => $this->whenLoaded(
                'attachedFiles',
                fn () => $this->attachedFiles->map(fn ($file) => [
                    'id' => $file->id,
                    'mime' => $file->mime,
                    'size' => $file->size,
                ])->values(),
            ),
        ];
    }
}
