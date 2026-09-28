<?php

namespace Tests\Feature\V8;

use App\Services\ML\MlArtifactRetentionService;
use App\Services\ML\MlLifecycleAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MlLifecycleRetentionGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_lifecycle_tick_skips_retention_when_disabled(): void
    {
        config([
            'ml_lifecycle.enabled' => true,
            'ml_lifecycle.retention.enabled' => false,
        ]);

        $this->mock(MlArtifactRetentionService::class, function ($mock): void {
            $mock->shouldReceive('isEnabled')->andReturn(false);
            $mock->shouldNotReceive('pruneAll');
        });

        $results = app(MlLifecycleAutomationService::class)->tick();
        $this->assertFalse(collect($results)->contains(fn (array $row): bool => ($row['action'] ?? '') === 'retention_pruned'));
    }

    public function test_lifecycle_tick_prunes_when_retention_enabled(): void
    {
        config([
            'ml_lifecycle.enabled' => true,
            'ml_lifecycle.retention.enabled' => true,
        ]);

        $this->mock(MlArtifactRetentionService::class, function ($mock): void {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('pruneAll')
                ->once()
                ->with(false)
                ->andReturn([['horizon' => '3m', 'model_version_id' => 1]]);
        });

        $results = app(MlLifecycleAutomationService::class)->tick();
        $this->assertTrue(collect($results)->contains(fn (array $row): bool => ($row['action'] ?? '') === 'retention_pruned'));
    }
}
