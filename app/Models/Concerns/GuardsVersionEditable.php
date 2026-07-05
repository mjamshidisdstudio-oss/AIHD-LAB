<?php

namespace App\Models\Concerns;

use App\Exceptions\Catalog\VersionNotEditableException;
use App\Models\ServiceVersion;
use Illuminate\Database\Eloquent\Model;

/**
 * Applied to models that describe the *content* of a service version (inputs,
 * options, outputs, waiting texts, option dependencies). Writing or deleting
 * any of them while the owning version is not a draft is rejected at the model
 * layer, so the "published versions are frozen" rule holds no matter which code
 * path (admin API, seeder, tinker) makes the change.
 */
trait GuardsVersionEditable
{
    public static function bootGuardsVersionEditable(): void
    {
        static::saving(function (Model $model): void {
            $model->assertOwningVersionEditable();
        });

        static::deleting(function (Model $model): void {
            $model->assertOwningVersionEditable();
        });
    }

    protected function assertOwningVersionEditable(): void
    {
        $version = $this->resolveOwningVersion();

        if ($version instanceof ServiceVersion && ! $version->isDraft()) {
            throw VersionNotEditableException::for($version);
        }
    }

    /**
     * Resolve the service version this row ultimately belongs to.
     */
    abstract protected function resolveOwningVersion(): ?ServiceVersion;
}
