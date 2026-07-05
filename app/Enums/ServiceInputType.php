<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * The kind of value a service input collects. "bundle" and
 * "conditional_group" are container inputs that group child inputs rather
 * than holding a scalar value themselves.
 */
enum ServiceInputType: string
{
    use HasValues;

    case Text = 'text';
    case Image = 'image';
    case Video = 'video';
    case Select = 'select';
    case Boolean = 'boolean';
    case Bundle = 'bundle';
    case ConditionalGroup = 'conditional_group';

    /**
     * Whether an order answer for this input type is stored as a scalar in
     * order_inputs (value_text / value_bool) rather than as options/files.
     */
    public function isScalar(): bool
    {
        return match ($this) {
            self::Text, self::Boolean => true,
            default => false,
        };
    }

    /**
     * Whether this input persists its answer through selected options.
     */
    public function usesOptions(): bool
    {
        return $this === self::Select;
    }
}
