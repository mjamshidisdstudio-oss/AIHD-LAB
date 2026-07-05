<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * How a result arrived: pushed to our webhook or pulled during GET polling.
 */
enum ResultSource: string
{
    use HasValues;

    case Webhook = 'webhook';
    case Poll = 'poll';
}
