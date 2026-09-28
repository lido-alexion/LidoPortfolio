<?php

namespace App\Exceptions;

use RuntimeException;

class MlTrainingRetryScheduledException extends RuntimeException
{
    public function __construct(public readonly int $trainingRunId)
    {
        parent::__construct('ML training retry scheduled.');
    }
}
