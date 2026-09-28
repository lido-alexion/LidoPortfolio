<?php

namespace App\Exceptions;

use RuntimeException;

class MlTrainingRunCancelledException extends RuntimeException
{
    public function __construct(public readonly int $trainingRunId)
    {
        parent::__construct('ML training run was cancelled.');
    }
}
