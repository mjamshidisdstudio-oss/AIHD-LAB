<?php

namespace App\Actions\Catalog;

use App\Enums\ServiceVersionStatus;
use App\Models\Service;
use App\Models\ServiceVersion;

/**
 * Create a fresh, empty draft version for a service. The version number is the
 * next integer after the service's current highest, so numbers never collide.
 */
class CreateDraftVersion
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(Service $service, array $attributes = []): ServiceVersion
    {
        $nextVersionNo = (int) $service->versions()->max('version_no') + 1;

        return $service->versions()->create([
            'version_no' => $nextVersionNo,
            'status' => ServiceVersionStatus::Draft,
            'coin_cost' => $attributes['coin_cost'] ?? 0,
            'regenerate_limit' => $attributes['regenerate_limit'] ?? 0,
            'response_timeout_s' => $attributes['response_timeout_s'] ?? 60,
            'get_interval_s' => $attributes['get_interval_s'] ?? 10,
            'max_get_attempts' => $attributes['max_get_attempts'] ?? 10,
            'post_url' => $attributes['post_url'] ?? null,
            'get_url' => $attributes['get_url'] ?? null,
            'published_at' => null,
        ]);
    }
}
