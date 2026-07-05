<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Where an order originated: the public site or an admin preview run that
 * exercises a version without charging a real customer.
 */
enum OrderSource: string
{
    use HasValues;

    case Site = 'site';
    case AdminPreview = 'admin_preview';
}
