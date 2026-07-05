<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Media type of an actual produced result. Mirrors ServiceOutputType but is
 * kept distinct because a result belongs to a request/response, not a schema.
 */
enum ResultType: string
{
    use HasValues;

    case Text = 'text';
    case Image = 'image';
    case Video = 'video';
}
