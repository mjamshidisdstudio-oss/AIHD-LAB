<?php

namespace App\Support\Core;

use RuntimeException;

/**
 * Raised by LocalCoreStubState::deduct() when the stub's in-memory balance
 * can't cover the amount. The stub controller renders this as HTTP 402,
 * mirroring the shape CoreCoinService expects from the real core.
 */
class InsufficientStubBalanceException extends RuntimeException {}
