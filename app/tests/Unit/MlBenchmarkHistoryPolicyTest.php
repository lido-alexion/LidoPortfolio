<?php

namespace Tests\Unit;

use App\Services\ML\MlBenchmarkHistoryPolicy;
use Carbon\Carbon;
use Tests\TestCase;

class MlBenchmarkHistoryPolicyTest extends TestCase
{
    public function test_policy_accounts_for_buckets_and_observation_overhead(): void
    {
        $policy = new MlBenchmarkHistoryPolicy();

        $this->assertSame(49, $policy->requiredCalendarMonths());
        $range = $policy->requiredRange(Carbon::parse('2026-09-22'));

        $this->assertSame('2022-08-01', $range['from']->toDateString());
        $this->assertSame('2026-09-22', $range['to']->toDateString());
        $this->assertSame(MlBenchmarkHistoryPolicy::VERSION, $range['policy']['version']);
        $this->assertSame(126, $range['policy']['maximum_label_observations']);
        $this->assertSame(63, $range['policy']['feature_lookback_observations']);
    }
}
