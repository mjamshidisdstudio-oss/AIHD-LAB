<?php

namespace App\Http\Controllers\Marketplace;

use App\Enums\CommentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Marketplace\ServiceCommentResource;
use App\Models\Service;
use App\Models\ServiceComment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Discussion for a service's currently published version. Comments are
 * scoped to service_version_id in the schema; the marketplace only ever
 * shows the one version end-customers actually experience (current_version_id).
 */
class CommentController extends Controller
{
    public function index(Service $service): AnonymousResourceCollection
    {
        $comments = $this->rootQuery($service)->latest()->limit(50)->get();

        return ServiceCommentResource::collection($comments);
    }

    public function store(Request $request, Service $service): JsonResponse
    {
        abort_if($service->current_version_id === null, 422, 'This service has no published version to comment on.');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
            'parent_id' => [
                'nullable',
                'uuid',
                Rule::exists('service_comments', 'id')
                    ->where('service_version_id', $service->current_version_id)
                    ->where('parent_id', null),
            ],
        ]);

        $comment = ServiceComment::create([
            'service_version_id' => $service->current_version_id,
            'user_ref' => (string) $request->userRef(),
            'body' => $data['body'],
            'parent_id' => $data['parent_id'] ?? null,
            'status' => CommentStatus::Published,
        ]);

        return ServiceCommentResource::make($comment->fresh(['replies']))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * @return Builder<ServiceComment>
     */
    private function rootQuery(Service $service)
    {
        return ServiceComment::query()
            ->where('service_version_id', $service->current_version_id)
            ->whereNull('parent_id')
            ->where('status', CommentStatus::Published)
            ->with(['replies' => fn ($q) => $q->where('status', CommentStatus::Published)->oldest()]);
    }
}
