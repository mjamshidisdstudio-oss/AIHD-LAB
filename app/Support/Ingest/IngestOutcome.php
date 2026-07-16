<?php

namespace App\Support\Ingest;

/**
 * The result of routing one result through the single ingest door.
 */
class IngestOutcome
{
    private function __construct(
        public readonly bool $duplicate,
        public readonly bool $completedOrder,
    ) {}

    public static function ingested(bool $completedOrder): self
    {
        return new self(false, $completedOrder);
    }

    public static function duplicate(): self
    {
        return new self(true, false);
    }

    public function wasIngested(): bool
    {
        return ! $this->duplicate;
    }
}
