<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Moderation state of a comment.
 */
enum CommentStatus: string
{
    use HasValues;

    case Published = 'published';
    case Hidden = 'hidden';
}
