<?php

namespace App\Http\Resources\Marketplace;

use App\Models\ServiceComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A published comment (and its one level of replies). user_ref is the opaque
 * core identity string — this app has no profile table to resolve a display
 * name or avatar from, so the raw ref is all there is to show.
 *
 * @mixin ServiceComment
 */
class ServiceCommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_ref' => $this->user_ref,
            'body' => $this->body,
            'sentiment' => $this->sentiment,
            'created_at' => $this->created_at,
            'replies' => self::collection($this->whenLoaded('replies')),
        ];
    }
}
