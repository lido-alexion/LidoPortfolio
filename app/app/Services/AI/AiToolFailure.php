<?php

namespace App\Services\AI;

class AiToolFailure extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $httpStatus = 422)
    {
        parent::__construct($reason);
    }
}
