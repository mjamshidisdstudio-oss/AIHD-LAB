<?php

namespace App\Exceptions\Coins;

use RuntimeException;

/**
 * Raised by a CoinService when a user cannot afford a charge. Rendered as HTTP
 * 402 (Payment Required) for the API.
 */
class InsufficientCoinsException extends RuntimeException
{
    public int $status = 402;

    public static function for(string $userRef, int $amount): self
    {
        return new self("User {$userRef} does not have {$amount} coins available.");
    }
}
