<?php

namespace App\Console\Commands;

use App\Models\WebhookDelivery;
use Illuminate\Console\Command;

/**
 * Retention janitor: after 30 days, a webhook_deliveries row keeps its
 * receipt (outcome, http_status, received_at, service/request links) forever
 * but has raw_body nulled out -- the payload is only useful for near-term
 * debugging, and there's no reason to keep an unbounded log of external
 * response bodies indefinitely.
 */
class PruneWebhookRawBodies extends Command
{
    protected $signature = 'retention:prune-webhook-bodies';

    protected $description = 'Null webhook_deliveries.raw_body for deliveries older than 30 days, keeping the receipt.';

    private const RETENTION_DAYS = 30;

    public function handle(): int
    {
        $cutoff = now()->subDays(self::RETENTION_DAYS);

        $pruned = WebhookDelivery::query()
            ->whereNotNull('raw_body')
            ->where('received_at', '<', $cutoff)
            ->update(['raw_body' => null]);

        $this->info("Pruned raw_body on {$pruned} webhook deliveries older than ".self::RETENTION_DAYS.' days.');

        return self::SUCCESS;
    }
}
