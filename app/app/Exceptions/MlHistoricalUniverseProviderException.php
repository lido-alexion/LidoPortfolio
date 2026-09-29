<?php

namespace App\Exceptions;

use RuntimeException;

class MlHistoricalUniverseProviderException extends RuntimeException
{
    /** @param array<string,mixed> $diagnostics */
    public function __construct(string $message, public readonly bool $retryable = false, public readonly array $diagnostics = [])
    {
        parent::__construct($message);
    }
}
