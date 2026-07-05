<?php

namespace App\Actions\Catalog;

use App\Enums\ServiceStatus;
use App\Enums\ServiceVersionStatus;
use App\Exceptions\Catalog\VersionNotPublishableException;
use App\Models\ServiceVersion;
use Illuminate\Support\Facades\DB;

/**
 * Publish a draft version in a single transaction:
 *   1. retire the service's currently published version (if any),
 *   2. mark this version published + stamp published_at,
 *   3. point services.current_version_id at it,
 *   4. reset consecutive_failures and clear an auto_disabled state.
 *
 * The previously published version is retired BEFORE this one is published so
 * the "one published version per service" unique guard never sees two at once.
 * Only draft versions may be published.
 */
class PublishVersion
{
    public function handle(ServiceVersion $version): ServiceVersion
    {
        if (! $version->isDraft()) {
            throw VersionNotPublishableException::for($version);
        }

        return DB::transaction(function () use ($version) {
            // Lock the service row so concurrent publishes serialise.
            $service = $version->service()->lockForUpdate()->firstOrFail();

            // Retire any other currently published version first.
            $service->versions()
                ->where('status', ServiceVersionStatus::Published->value)
                ->whereKeyNot($version->getKey())
                ->update([
                    'status' => ServiceVersionStatus::Retired->value,
                    'updated_at' => now(),
                ]);

            $version->update([
                'status' => ServiceVersionStatus::Published,
                'published_at' => now(),
            ]);

            $service->current_version_id = $version->getKey();
            $service->consecutive_failures = 0;
            if ($service->status === ServiceStatus::AutoDisabled) {
                // A fresh publish clears an automatic disable; an operator pause
                // is left untouched.
                $service->status = ServiceStatus::Active;
            }
            $service->save();

            return $version->refresh();
        });
    }
}
