<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Media type produced by a single declared output slot of a service version.
 */
enum ServiceOutputType: string
{
    use HasValues;

    case Text = 'text';
    case Image = 'image';
    case Video = 'video';
}
