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
        public readonly ?string $rejectedReason = null,
    ) {}

    public static function ingested(bool $completedOrder): self
    {
        return new self(false, $completedOrder);
    }

    public static function duplicate(): self
    {
        return new self(true, false);
    }

    /**
     * The delivery was refused outright -- nothing was written to `results`.
     * Distinct from `duplicate` (a legitimate re-delivery of already-ingested
     * content): a rejection means the delivery itself is invalid, e.g. a
     * media_id it has no right to reference.
     */
    public static function rejected(string $reason): self
    {
        return new self(false, false, $reason);
    }

    public function wasIngested(): bool
    {
        return ! $this->duplicate && ! $this->wasRejected();
    }

    public function wasRejected(): bool
    {
        return $this->rejectedReason !== null;
    }
}
