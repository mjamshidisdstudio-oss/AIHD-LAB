<?php

namespace App\Console\Commands;

use App\Enums\InteractionKind;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes the denormalised marketplace-grid columns on `services` from
 * their source-of-truth tables. A full recompute (never an increment), so a
 * rerun against unchanged data reproduces identical values -- there is
 * nothing to drift.
 */
class RefreshServiceMetrics extends Command
{
    protected $signature = 'services:refresh-metrics';

    protected $description = 'Recompute services.vote_up/vote_down, avg_latency_ms, and trending_rank.';

    /**
     * How far back "recent interest" looks for trending_rank.
     */
    private const TRENDING_WINDOW_DAYS = 7;

    public function handle(): int
    {
        $this->refreshVoteCounts();
        $this->refreshAvgLatency();
        $this->refreshTrendingRank();

        $this->info('Service metrics refreshed.');

        return self::SUCCESS;
    }

    /**
     * vote_up / vote_down: a plain count of service_votes by sign. Services
     * with zero votes get 0 (never null -- unlike avg_latency_ms, "no votes
     * yet" and "zero votes" are the same thing here).
     */
    private function refreshVoteCounts(): void
    {
        DB::statement('
            UPDATE services s
            LEFT JOIN (
                SELECT service_id,
                       SUM(value = 1) AS up,
                       SUM(value = -1) AS down
                FROM service_votes
                GROUP BY service_id
            ) v ON v.service_id = s.id
            SET s.vote_up = COALESCE(v.up, 0),
                s.vote_down = COALESCE(v.down, 0)
        ');
    }

    /**
     * avg_latency_ms: the mean results.latency_ms across a service's
     * COMPLETED, non-admin_preview orders only. Stays NULL (never 0) for a
     * service with no such order yet -- 0ms would read as "instant," which
     * is a lie, whereas null means "no data."
     */
    private function refreshAvgLatency(): void
    {
        DB::statement("
            UPDATE services s
            LEFT JOIN (
                SELECT o.service_id, AVG(r.latency_ms) AS avg_latency
                FROM results r
                JOIN requests req ON req.id = r.request_id
                JOIN orders o ON o.id = req.order_id
                WHERE o.status = '".OrderStatus::Completed->value."'
                  AND o.source != '".OrderSource::AdminPreview->value."'
                GROUP BY o.service_id
            ) l ON l.service_id = s.id
            SET s.avg_latency_ms = l.avg_latency
        ");
    }

    /**
     * trending_rank formula (the interest ladder, weighted toward stronger
     * signals of intent, over the last TRENDING_WINDOW_DAYS):
     *
     *   score = 3 * completions        (orders that reached `completed`)
     *         + 2 * downloads          (interactions of kind=download)
     *         + 2 * regenerations      (orders with a regenerated_from_order_id)
     *         + 1 * net_votes          (upvotes - downvotes on service_votes)
     *
     * All four counts exclude admin_preview activity entirely -- an
     * operator's draft preview run is not customer interest. trending_rank
     * is a dense rank over services with a POSITIVE score (1 = most
     * trending, ties share a rank). A service with no recent interest at
     * all gets trending_rank = NULL ("not currently trending"), the same
     * null-means-no-data convention as avg_latency_ms, rather than an
     * arbitrary tied rank among a crowd of zero-score services.
     */
    private function refreshTrendingRank(): void
    {
        $since = now()->subDays(self::TRENDING_WINDOW_DAYS)->toDateTimeString();

        $completions = DB::table('orders')
            ->select('service_id', DB::raw('COUNT(*) as n'))
            ->where('status', OrderStatus::Completed->value)
            ->where('source', OrderSource::Site->value)
            ->where('created_at', '>=', $since)
            ->groupBy('service_id')
            ->pluck('n', 'service_id');

        $regenerations = DB::table('orders')
            ->select('service_id', DB::raw('COUNT(*) as n'))
            ->where('source', OrderSource::Site->value)
            ->whereNotNull('regenerated_from_order_id')
            ->where('created_at', '>=', $since)
            ->groupBy('service_id')
            ->pluck('n', 'service_id');

        $downloads = DB::table('interactions as i')
            ->leftJoin('orders as io', 'io.id', '=', 'i.order_id')
            ->select('i.service_id', DB::raw('COUNT(*) as n'))
            ->where('i.kind', InteractionKind::Download->value)
            ->where('i.created_at', '>=', $since)
            ->where(function ($q) {
                $q->whereNull('io.id')->orWhere('io.source', '!=', OrderSource::AdminPreview->value);
            })
            ->groupBy('i.service_id')
            ->pluck('n', 'i.service_id');

        $netVotes = DB::table('service_votes')
            ->select('service_id', DB::raw('SUM(value) as n'))
            ->where('created_at', '>=', $since)
            ->groupBy('service_id')
            ->pluck('n', 'service_id');

        $serviceIds = DB::table('services')->pluck('id');

        $scores = $serviceIds->mapWithKeys(function ($id) use ($completions, $regenerations, $downloads, $netVotes) {
            $score = 3 * (int) ($completions[$id] ?? 0)
                + 2 * (int) ($downloads[$id] ?? 0)
                + 2 * (int) ($regenerations[$id] ?? 0)
                + (int) ($netVotes[$id] ?? 0);

            return [$id => $score];
        });

        // Full recompute every run: clear first, then assign dense ranks
        // only to positive scores, so a service that cools off loses its
        // rank instead of keeping a stale one.
        DB::table('services')->update(['trending_rank' => null]);

        $ranked = $scores->filter(fn ($score) => $score > 0)->sort()->reverse();

        $rank = 0;
        $previousScore = null;
        foreach ($ranked as $id => $score) {
            if ($score !== $previousScore) {
                $rank++;
                $previousScore = $score;
            }
            DB::table('services')->where('id', $id)->update(['trending_rank' => $rank]);
        }
    }
}
