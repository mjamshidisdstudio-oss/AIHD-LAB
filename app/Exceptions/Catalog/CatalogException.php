<?php

namespace App\Exceptions\Catalog;

use RuntimeException;

/**
 * Base class for catalog/versioning domain rule violations. Carries the HTTP
 * status the admin API should surface (see AppServiceProvider exception render).
 */
abstract class CatalogException extends RuntimeException
{
    /** HTTP status code the API should return for this violation. */
    public int $status = 422;
}
