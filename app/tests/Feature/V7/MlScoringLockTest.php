<?php

namespace Tests\Feature\V7;

use App\Models\V7\MlModelVersion;
use App\Services\Fundamentals\FundamentalDataService;
use App\Services\ML\MlDeterministicBaselineAdapter;
use App\Services\ML\MlPythonAdapter;
use App\Services\ML\MlScoringService;
use App\Services\ML\MlTrainingDatasetBuilder;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class MlScoringLockTest extends TestCase
{
    public function test_retraining_uses_the_configured_safe_lease_and_short_acquisition_wait(): void
    {
        config(['ml.retrain_lock_seconds' => 14400]);
        $lock = Mockery::mock();
        $lock->shouldReceive('block')->once()->with(15, Mockery::type('Closure'))->andReturn(new MlModelVersion());
        Cache::shouldReceive('lock')->once()->with('stox-ml-retrain-3m', 14400)->andReturn($lock);

        $service = $this->service();
        $this->assertInstanceOf(MlModelVersion::class, $service->retrain('3m', null, null));
    }

    public function test_each_horizon_has_an_independent_safe_lock_key(): void
    {
        config(['ml.retrain_lock_seconds' => 14400]);
        foreach (['1m', '6m'] as $horizon) {
            $lock = Mockery::mock();
            $lock->shouldReceive('block')->once()->with(15, Mockery::type('Closure'))->andReturn(new MlModelVersion());
            Cache::shouldReceive('lock')->once()->with('stox-ml-retrain-'.$horizon, 14400)->andReturn($lock);
        }

        $service = $this->service();
        $this->assertInstanceOf(MlModelVersion::class, $service->retrain('1m', null, null));
        $this->assertInstanceOf(MlModelVersion::class, $service->retrain('6m', null, null));
    }

    private function service(): MlScoringService
    {
        return new MlScoringService(
            Mockery::mock(FundamentalDataService::class),
            Mockery::mock(MlTrainingDatasetBuilder::class),
            Mockery::mock(MlPythonAdapter::class),
            app(MlDeterministicBaselineAdapter::class),
        );
    }
}
