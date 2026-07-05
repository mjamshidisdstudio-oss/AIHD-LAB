<?php

namespace App\Support\External;

/**
 * One produced result returned by an external service poll. File results
 * (image/video) carry raw bytes to persist; text results carry their string.
 */
class ExternalResultItem
{
    public function __construct(
        public readonly int $resultNumber,
        public readonly string $type,
        public readonly ?string $text = null,
        public readonly ?string $mime = null,
        public readonly ?string $bytes = null,
    ) {}

    public function isFile(): bool
    {
        return $this->bytes !== null;
    }
}
