<?php

namespace App\Console\Commands;

use Illuminate\Cache\RateLimiter;
use Illuminate\Console\Command;

/**
 * Test-support only, used by tests/Acceptance: clears one named rate
 * limiter's bucket for one identity, using the same key format
 * Illuminate\Routing\Middleware\ThrottleRequests computes for a
 * `throttle:<limiter>` named limiter (md5($limiterName.$by) -- shouldHashKeys
 * defaults true and is never overridden in this app). The acceptance suite
 * legitimately submits more than 10 orders as the same dev-user while
 * exercising its failure-path scenarios back to back; this resets the
 * submit-order bucket between clusters of steps without touching any other
 * cached state (balances, held transactions, etc. are untouched).
 */
class AcceptanceResetRateLimit extends Command
{
    protected $signature = 'acceptance:reset-rate-limit {limiter} {identity}';

    protected $description = 'Test-support: clear one named rate limiter bucket for one identity';

    public function handle(RateLimiter $limiter): int
    {
        $key = md5($this->argument('limiter').$this->argument('identity'));
        $limiter->clear($key);

        return self::SUCCESS;
    }
}
