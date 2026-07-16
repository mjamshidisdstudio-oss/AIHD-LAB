<?php

namespace App\Http\Controllers\Marketplace;

use App\Enums\CommentStatus;
use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Marketplace\ServiceCardResource;
use App\Http\Resources\Marketplace\ServiceDetailResource;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public (authenticated end-customer) read access to the catalog: the
 * marketplace grid/board and a single service's detail page. Only Active
 * services are ever listed here — a paused or auto-disabled service cannot
 * take orders, so surfacing it in the marketplace would just be a dead end.
 */
class CatalogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Service::query()
            ->withMarketplaceContext((string) $request->userRef())
            ->with('currentVersion')
            ->where('status', ServiceStatus::Active);

        if ($request->filled('category') && $request->query('category') !== 'all') {
            $query->where('category', $request->query('category'));
        }

        if ($request->boolean('saved')) {
            $query->whereHas('bookmarks', fn ($q) => $q->where('user_ref', (string) $request->userRef()));
        }

        if ($request->filled('q')) {
            $term = '%'.$request->query('q').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('description', 'like', $term));
        }

        match ($request->query('sort', 'hot')) {
            'top' => $query->orderByDesc('vote_up'),
            'new' => $query->orderByDesc('created_at'),
            default => $query->orderByRaw('trending_rank IS NULL, trending_rank ASC'),
        };

        return ServiceCardResource::collection($query->get());
    }

    public function show(Request $request, Service $service): ServiceDetailResource
    {
        abort_unless($service->status === ServiceStatus::Active, 404);

        $service = Service::query()
            ->withMarketplaceContext((string) $request->userRef())
            ->with([
                'currentVersion.inputs.options.parentOptions',
                'currentVersion.outputs',
                'currentVersion.waitingTexts',
            ])
            ->whereKey($service->id)
            ->firstOrFail();

        $comments = $service->currentVersion === null
            ? collect()
            : $service->currentVersion->comments()
                ->whereNull('parent_id')
                ->where('status', CommentStatus::Published)
                ->with(['replies' => fn ($q) => $q->where('status', CommentStatus::Published)->oldest()])
                ->latest()
                ->limit(50)
                ->get();

        $similar = Service::query()
            ->withMarketplaceContext((string) $request->userRef())
            ->with('currentVersion')
            ->where('status', ServiceStatus::Active)
            ->where('category', $service->category)
            ->whereKeyNot($service->id)
            ->inRandomOrder()
            ->limit(3)
            ->get();

        if ($similar->count() < 3) {
            $excludeIds = $similar->pluck('id')->push($service->id);
            $fill = Service::query()
                ->withMarketplaceContext((string) $request->userRef())
                ->with('currentVersion')
                ->where('status', ServiceStatus::Active)
                ->whereKeyNot($excludeIds)
                ->inRandomOrder()
                ->limit(3 - $similar->count())
                ->get();
            $similar = $similar->merge($fill);
        }

        return new ServiceDetailResource($service, $comments, $similar->values());
    }
}
