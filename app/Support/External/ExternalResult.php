<?php

namespace App\Support\External;

/**
 * The full set of results returned by a single completed poll.
 */
class ExternalResult
{
    /**
     * @param  array<int, ExternalResultItem>  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $latencyMs,
    ) {}
}
