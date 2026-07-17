<?php

namespace App\Services\Analytics;

use App\Enums\EntryMode;
use App\Enums\InteractionKind;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Named, documented analytics queries over the interest ladder
 * (generate -> complete -> download -> regenerate -> vote). Every query here
 * excludes source=admin_preview: an operator's draft preview run is not
 * customer interest and would skew every one of these numbers.
 *
 * These report independent counts per rung, not a strict per-user
 * conversion funnel -- votes in particular are not tied to any single
 * order (service_votes has no order_id), so "of the people who generated,
 * how many voted" isn't a question the schema can answer. Documented here
 * rather than implying a funnel the data doesn't support.
 */
class AnalyticsRepository
{
    /**
     * The interest ladder for one service: generate/complete/download/
     * regenerate/vote counts overall and broken down by version.
     *
     * @return array{
     *     service_id: string,
     *     overall: array<string, int>,
     *     by_version: Collection<int, array<string, mixed>>,
     * }
     */
    public function interestLadder(string $serviceId): array
    {
        $versionIds = DB::table('service_versions')
            ->where('service_id', $serviceId)
            ->pluck('version_no', 'id');

        $byVersion = $versionIds->map(fn ($versionNo, $versionId) => array_merge(
            ['version_id' => $versionId, 'version_no' => $versionNo],
            $this->ladderCounts(serviceId: $serviceId, versionId: $versionId),
        ))->values();

        return [
            'service_id' => $serviceId,
            'overall' => $this->ladderCounts(serviceId: $serviceId),
            'by_version' => $byVersion,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function ladderCounts(string $serviceId, ?string $versionId = null): array
    {
        $orders = DB::table('orders')
            ->where('service_id', $serviceId)
            ->where('source', OrderSource::Site->value)
            ->when($versionId, fn ($q) => $q->where('service_version_id', $versionId));

        $generate = (clone $orders)->count();
        $complete = (clone $orders)->where('status', OrderStatus::Completed->value)->count();
        $regenerate = (clone $orders)->whereNotNull('regenerated_from_order_id')->count();

        $downloads = DB::table('interactions as i')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('i.kind', InteractionKind::Download->value)
            ->where('o.service_id', $serviceId)
            ->where('o.source', OrderSource::Site->value)
            ->when($versionId, fn ($q) => $q->where('o.service_version_id', $versionId))
            ->count();

        $votes = DB::table('service_votes')
            ->where('service_id', $serviceId)
            ->when($versionId, fn ($q) => $q->where('service_version_id', $versionId));

        return [
            'generate' => $generate,
            'complete' => $complete,
            'download' => $downloads,
            'regenerate' => $regenerate,
            'vote_up' => (clone $votes)->where('value', 1)->count(),
            'vote_down' => (clone $votes)->where('value', -1)->count(),
        ];
    }

    /**
     * Per-version metrics for a service, side by side, so callers can answer
     * "does v2 beat v1?" on completion rate, latency, downloads, and votes --
     * this is exactly why orders pin service_version_id rather than only
     * pointing at the service.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function versionComparison(string $serviceId): Collection
    {
        $versions = DB::table('service_versions')
            ->where('service_id', $serviceId)
            ->orderBy('version_no')
            ->get(['id', 'version_no']);

        return $versions->map(function ($version) use ($serviceId) {
            $orders = DB::table('orders')
                ->where('service_id', $serviceId)
                ->where('service_version_id', $version->id)
                ->where('source', OrderSource::Site->value);

            $total = (clone $orders)->count();
            $completed = (clone $orders)->where('status', OrderStatus::Completed->value)->count();

            $avgLatency = DB::table('results as r')
                ->join('requests as req', 'req.id', '=', 'r.request_id')
                ->join('orders as o', 'o.id', '=', 'req.order_id')
                ->where('o.service_version_id', $version->id)
                ->where('o.status', OrderStatus::Completed->value)
                ->where('o.source', OrderSource::Site->value)
                ->avg('r.latency_ms');

            $downloads = DB::table('interactions as i')
                ->join('orders as o', 'o.id', '=', 'i.order_id')
                ->where('i.kind', InteractionKind::Download->value)
                ->where('o.service_version_id', $version->id)
                ->where('o.source', OrderSource::Site->value)
                ->count();

            $votes = DB::table('service_votes')->where('service_version_id', $version->id);

            return [
                'version_id' => $version->id,
                'version_no' => $version->version_no,
                'orders' => $total,
                'completed' => $completed,
                'completion_rate' => $total > 0 ? round($completed / $total, 4) : null,
                'avg_latency_ms' => $avgLatency !== null ? (int) round($avgLatency) : null,
                'downloads' => $downloads,
                'vote_up' => (clone $votes)->where('value', 1)->count(),
                'vote_down' => (clone $votes)->where('value', -1)->count(),
            ];
        });
    }

    /**
     * Wizard vs chat: completion rate and drop-off (orders that never
     * reached `completed` -- still processing or failed) by entry_mode, for
     * one service.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function entryModeFunnel(string $serviceId): Collection
    {
        return collect(EntryMode::cases())->map(function (EntryMode $mode) use ($serviceId) {
            $orders = DB::table('orders')
                ->where('service_id', $serviceId)
                ->where('source', OrderSource::Site->value)
                ->where('entry_mode', $mode->value);

            $total = (clone $orders)->count();
            $completed = (clone $orders)->where('status', OrderStatus::Completed->value)->count();

            return [
                'entry_mode' => $mode->value,
                'orders' => $total,
                'completed' => $completed,
                'completion_rate' => $total > 0 ? round($completed / $total, 4) : null,
                'drop_off_rate' => $total > 0 ? round(($total - $completed) / $total, 4) : null,
            ];
        });
    }
}
