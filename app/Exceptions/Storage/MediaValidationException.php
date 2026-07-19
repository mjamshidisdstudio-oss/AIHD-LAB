<?php

namespace App\Exceptions\Storage;

use App\Enums\MediaType;
use RuntimeException;

/**
 * Raised by StoreMedia when an upload fails the config-driven per-type media
 * policy (config/media.php) -- either a real, content-sniffed mime not on the
 * expected type's allow-list, or a file over that type's size ceiling.
 *
 * Rendered as 422 for our own input-upload path (see bootstrap/app.php) --
 * that path never distinguishes the two. The external-facing POST /api/storage
 * endpoint catches this directly instead and maps $tooLarge to 413, anything
 * else to 422, since only that endpoint's contract makes the distinction.
 */
class MediaValidationException extends RuntimeException
{
    public int $status = 422;

    private function __construct(
        string $message,
        public readonly MediaType $expectedType,
        public readonly bool $tooLarge,
    ) {
        parent::__construct($message);
    }

    public static function mimeNotAllowed(MediaType $expectedType, string $detectedMime): self
    {
        return new self(
            "Expected a {$expectedType->value} file, but the upload's real content is {$detectedMime}.",
            $expectedType,
            tooLarge: false,
        );
    }

    public static function tooLarge(MediaType $expectedType, int $sizeBytes, int $maxBytes): self
    {
        return new self(
            "{$expectedType->value} uploads are capped at {$maxBytes} bytes; this file is {$sizeBytes} bytes.",
            $expectedType,
            tooLarge: true,
        );
    }
}
