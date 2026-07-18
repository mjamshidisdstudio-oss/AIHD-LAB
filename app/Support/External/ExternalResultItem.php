<?php

namespace App\Support\External;

/**
 * One produced result returned by an external service poll. File results
 * (image/video) carry either raw bytes to persist directly, or a media_id
 * referencing a file the provider already uploaded via POST /storage --
 * text results carry their string.
 */
class ExternalResultItem
{
    public function __construct(
        public readonly int $resultNumber,
        public readonly string $type,
        public readonly ?string $text = null,
        public readonly ?string $mime = null,
        public readonly ?string $bytes = null,
        public readonly ?string $mediaId = null,
    ) {}

    public function isFile(): bool
    {
        return $this->bytes !== null;
    }

    public function hasMediaReference(): bool
    {
        return $this->mediaId !== null;
    }
}
