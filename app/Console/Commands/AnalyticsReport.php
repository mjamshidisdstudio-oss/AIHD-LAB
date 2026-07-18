<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Services\Analytics\AnalyticsRepository;
use Illuminate\Console\Command;

/**
 * Ops-facing runner for AnalyticsRepository's named queries -- the interest
 * ladder, version comparison, and wizard-vs-chat funnel -- against one
 * service (or every service, if none is given).
 */
class AnalyticsReport extends Command
{
    protected $signature = 'analytics:report {service? : A service slug or id; omit to report on every service} {--json : Output machine-readable JSON instead of formatted tables}';

    protected $description = 'Print the interest ladder, version comparison, and entry-mode funnel for a service.';

    public function handle(AnalyticsRepository $analytics): int
    {
        $services = $this->argument('service')
            ? Service::query()->where('slug', $this->argument('service'))->orWhere('id', $this->argument('service'))->get()
            : Service::query()->get();

        if ($services->isEmpty()) {
            $this->error('No matching service.');

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($services->map(fn ($service) => [
                'service_id' => $service->id,
                'slug' => $service->slug,
                'interest_ladder' => $analytics->interestLadder($service->id),
                'version_comparison' => $analytics->versionComparison($service->id)->all(),
                'entry_mode_funnel' => $analytics->entryModeFunnel($service->id)->all(),
            ])->all()));

            return self::SUCCESS;
        }

        foreach ($services as $service) {
            $this->line("<fg=cyan;options=bold>{$service->name}</> ({$service->slug})");

            $ladder = $analytics->interestLadder($service->id);
            $this->table(
                ['generate', 'complete', 'download', 'regenerate', 'vote_up', 'vote_down'],
                [$ladder['overall']],
            );

            $comparison = $analytics->versionComparison($service->id);
            if ($comparison->isNotEmpty()) {
                $this->line('Version comparison:');
                $this->table(
                    ['version_no', 'orders', 'completed', 'completion_rate', 'avg_latency_ms', 'downloads', 'vote_up', 'vote_down'],
                    $comparison->map(fn ($row) => collect($row)->except('version_id')->all())->all(),
                );
            }

            $funnel = $analytics->entryModeFunnel($service->id);
            $this->line('Wizard vs chat:');
            $this->table(
                ['entry_mode', 'orders', 'completed', 'completion_rate', 'drop_off_rate'],
                $funnel->all(),
            );

            $this->newLine();
        }

        return self::SUCCESS;
    }
}
