<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Whether a stored file is a user-supplied input or a generated result.
 */
enum FileKind: string
{
    use HasValues;

    case Input = 'input';
    case Result = 'result';
}
