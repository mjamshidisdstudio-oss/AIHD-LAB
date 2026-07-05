<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Overall state of a user's generation order.
 */
enum OrderStatus: string
{
    use HasValues;

    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
