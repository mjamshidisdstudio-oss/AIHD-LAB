<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CommentSentiment;
use App\Enums\CommentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminCommentResource;
use App\Models\Service;
use App\Models\ServiceComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Community moderation: every comment across all of a service's versions
 * (not just the currently published one, unlike the marketplace's read-only
 * view), with hide/publish and admin replies.
 */
class CommentController extends Controller
{
    public function index(Request $request, Service $service): AnonymousResourceCollection
    {
        $query = ServiceComment::query()
            ->whereIn('service_version_id', $service->versions()->pluck('id'))
            ->whereNull('parent_id')
            ->with(['replies' => fn ($q) => $q->oldest()]);

        if ($request->filled('service_version_id')) {
            $query->where('service_version_id', $request->query('service_version_id'));
        }

        $comments = $query->latest()->paginate(20)->withQueryString();

        return AdminCommentResource::collection($comments);
    }

    public function update(Request $request, ServiceComment $comment): AdminCommentResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(CommentStatus::class)],
        ]);

        $comment->update($data);

        return AdminCommentResource::make($comment->fresh());
    }

    public function reply(Request $request, ServiceComment $comment): JsonResponse
    {
        abort_if($comment->parent_id !== null, 422, 'Cannot reply to a reply.');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $reply = ServiceComment::create([
            'service_version_id' => $comment->service_version_id,
            'user_ref' => 'admin:'.$request->user()->id,
            'body' => $data['body'],
            'sentiment' => CommentSentiment::Neutral,
            'status' => CommentStatus::Published,
            'parent_id' => $comment->id,
        ]);

        return AdminCommentResource::make($reply)->response()->setStatusCode(201);
    }
}
