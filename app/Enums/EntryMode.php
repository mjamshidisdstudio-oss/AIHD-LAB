<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * How the user assembled the order inputs: the step-by-step wizard or the
 * conversational chat flow.
 */
enum EntryMode: string
{
    use HasValues;

    case Wizard = 'wizard';
    case Chat = 'chat';
}
