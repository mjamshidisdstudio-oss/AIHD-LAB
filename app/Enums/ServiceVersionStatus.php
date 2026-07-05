<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Publication state of a service version. Only one version is normally
 * "published" at a time; drafts are editable and retired ones are frozen.
 */
enum ServiceVersionStatus: string
{
    use HasValues;

    case Draft = 'draft';
    case Published = 'published';
    case Retired = 'retired';
}
