<?php

namespace App\Http\Resources\Marketplace;

use App\Models\Service;
use App\Models\ServiceComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Full service detail page: everything on the card plus the published
 * version's form/output/waiting-text structure, the discussion thread, and a
 * same-category "similar services" rail.
 *
 * `description` is the long "About" copy; `tagline` (on the card) is now its
 * own column, falling back to `description` when unset.
 *
 * comments/similar are provided by the controller as plain collections (not
 * Eloquent relations of Service), passed in via the constructor.
 *
 * @mixin Service
 */
class ServiceDetailResource extends JsonResource
{
    /**
     * @param  Collection<int, ServiceComment>  $comments
     * @param  Collection<int, Service>  $similar
     */
    public function __construct(
        Service $resource,
        private readonly Collection $comments,
        private readonly Collection $similar,
    ) {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(
            ServiceCardResource::make($this->resource)->toArray($request),
            [
                'description' => $this->description,
                'gallery' => $this->gallery ?? [],
                'before_image_url' => $this->before_image_url,
                'after_image_url' => $this->after_image_url,
                'version' => ServiceVersionResource::make($this->whenLoaded('currentVersion')),
                'comment_count' => $this->comments->reduce(
                    fn (int $carry, $comment) => $carry + 1 + $comment->replies->count(),
                    0,
                ),
                'comments' => ServiceCommentResource::collection($this->comments),
                'similar' => ServiceCardResource::collection($this->similar),
            ],
        );
    }
}
