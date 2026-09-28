<?php

namespace Tests\Unit\Screener;

use App\Services\Fundamentals\FundamentalScreenerOperandService;
use App\Services\ML\MlScreenerOperandService;
use App\Services\Screener\ScreenerEvaluationService;
use App\Services\Screener\TechnicalIndicatorService;
use PHPUnit\Framework\MockObject\Generator\Generator as MockGenerator;

final class ScreenerEvaluationTestSupport
{
    public static function evaluationService(?TechnicalIndicatorService $indicators = null): ScreenerEvaluationService
    {
        $generator = new MockGenerator;
        $fundamentals = $generator->testDouble(
            FundamentalScreenerOperandService::class,
            true,
            callOriginalConstructor: false,
        );
        $fundamentals->method('supports')->willReturn(false);
        $ml = $generator->testDouble(
            MlScreenerOperandService::class,
            true,
            callOriginalConstructor: false,
        );
        $ml->method('supports')->willReturn(false);

        return new ScreenerEvaluationService(
            $indicators ?? new TechnicalIndicatorService,
            $fundamentals,
            $ml,
        );
    }
}
