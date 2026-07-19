<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * The media-validation-policy vocabulary (config/media.php's keys), shared by
 * both service inputs (ServiceInputType::Image/Video) and service outputs
 * (ServiceOutputType) -- StoreMedia validates against whichever of those two
 * the specific upload's expected type maps to, via MediaType::from($value).
 */
enum MediaType: string
{
    use HasValues;

    case Image = 'image';
    case Video = 'video';
    case Text = 'text';
}
