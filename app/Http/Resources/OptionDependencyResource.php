<?php

namespace App\Http\Resources;

use App\Models\OptionDependency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OptionDependency
 */
class OptionDependencyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'option_id' => $this->option_id,
            'parent_option_id' => $this->parent_option_id,
        ];
    }
}
