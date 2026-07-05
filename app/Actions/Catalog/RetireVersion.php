<?php

namespace App\Actions\Catalog;

use App\Enums\ServiceVersionStatus;
use App\Models\ServiceVersion;
use Illuminate\Support\Facades\DB;

/**
 * Retire a version. If it was the service's current (published) version, the
 * service is left with no current version until another is published.
 */
class RetireVersion
{
    public function handle(ServiceVersion $version): ServiceVersion
    {
        return DB::transaction(function () use ($version) {
            $service = $version->service()->lockForUpdate()->firstOrFail();

            $version->update(['status' => ServiceVersionStatus::Retired]);

            if ($service->current_version_id === $version->getKey()) {
                $service->update(['current_version_id' => null]);
            }

            return $version->refresh();
        });
    }
}
