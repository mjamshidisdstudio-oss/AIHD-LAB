<?php

namespace App\Exceptions\Catalog;

use App\Models\ServiceVersion;

/**
 * Thrown when a version cannot transition to published (e.g. it is retired, or
 * already published).
 */
class VersionNotPublishableException extends CatalogException
{
    public static function for(ServiceVersion $version): self
    {
        return new self(
            "Service version {$version->getKey()} cannot be published from state "
            ."{$version->status->value}; only draft versions can be published."
        );
    }
}
