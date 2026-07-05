<?php

namespace App\Actions\Catalog;

use App\Enums\ServiceKind;
use App\Enums\ServiceStatus;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create a catalog service together with its first, empty draft version (v1).
 * The service starts with no current_version_id — nothing is served until a
 * version is published.
 */
class CreateService
{
    public function __construct(private CreateDraftVersion $createDraftVersion) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes): Service
    {
        return DB::transaction(function () use ($attributes) {
            $service = Service::create([
                'slug' => $attributes['slug'],
                'name' => $attributes['name'],
                'description' => $attributes['description'] ?? null,
                'image_url' => $attributes['image_url'] ?? null,
                'kind' => $attributes['kind'] ?? ServiceKind::Internal,
                'external_url' => $attributes['external_url'] ?? null,
                'category' => $attributes['category'],
                // Hashed by the model cast; generated when not supplied.
                'service_secret' => $attributes['service_secret'] ?? Str::random(48),
                'status' => $attributes['status'] ?? ServiceStatus::Active,
            ]);

            $this->createDraftVersion->handle($service, $attributes['version'] ?? []);

            return $service->refresh();
        });
    }
}
