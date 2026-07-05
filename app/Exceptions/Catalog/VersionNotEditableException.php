<?php

namespace App\Exceptions\Catalog;

use App\Models\ServiceVersion;

/**
 * Thrown when code attempts to mutate the inputs/options/outputs of a version
 * that is not a draft. Published and retired versions are frozen — duplicate
 * to a new draft first.
 */
class VersionNotEditableException extends CatalogException
{
    public static function for(ServiceVersion $version): self
    {
        return new self(
            "Service version {$version->getKey()} is {$version->status->value}; "
            .'only draft versions can be edited. Duplicate it to a new draft first.'
        );
    }
}
