<?php

namespace App\Enums\Concerns;

/**
 * Shared helpers for backed enums so the same value set can drive
 * migration ENUM columns, validation rules, and factory random picks.
 */
trait HasValues
{
    /**
     * The list of backing values, e.g. ['internal', 'external'].
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * A random case — handy for factories.
     */
    public static function random(): self
    {
        $cases = self::cases();

        return $cases[array_rand($cases)];
    }
}
