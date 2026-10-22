<?php

namespace App\Services\Fundamentals;

use App\Models\V7\FundamentalBootstrapRun;

final readonly class BootstrapLaunchResult
{
    public function __construct(
        public FundamentalBootstrapRun $run,
        public bool $created,
    ) {}
}
