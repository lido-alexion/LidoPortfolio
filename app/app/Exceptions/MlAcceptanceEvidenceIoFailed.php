<?php

namespace App\Exceptions;

class MlAcceptanceEvidenceIoFailed extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('PIT evidence journal I/O failed.');
    }
}
