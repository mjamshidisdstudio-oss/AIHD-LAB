<?php

namespace App\Http\Resources\Admin;

use App\Models\ServiceComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceComment
 */
class AdminCommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_version_id' => $this->service_version_id,
            'user_ref' => $this->user_ref,
            'body' => $this->body,
            'sentiment' => $this->sentiment,
            'status' => $this->status,
            'parent_id' => $this->parent_id,
            'created_at' => $this->created_at,
            'replies' => self::collection($this->whenLoaded('replies')),
        ];
    }
}
