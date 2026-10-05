<?php

namespace App\Services\Fundamentals\Historical;

use RuntimeException;

class ExchangeRequestDeferred extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds, string $reason)
    {
        parent::__construct('Exchange request deferred: '.$reason);
    }
}
