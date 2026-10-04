<?php

namespace App\Exceptions;

class MlAcceptanceEvidenceQuotaExceeded extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('PIT evidence quota exceeded.');
    }
}
