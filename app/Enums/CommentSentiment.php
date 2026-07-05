<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Coarse sentiment classification applied to a user comment.
 */
enum CommentSentiment: string
{
    use HasValues;

    case Positive = 'positive';
    case Neutral = 'neutral';
    case Negative = 'negative';
}
