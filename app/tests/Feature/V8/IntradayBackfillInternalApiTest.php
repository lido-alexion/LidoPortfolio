<?php

namespace Tests\Feature\V8;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntradayBackfillInternalApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['intraday_ml_platform.internal_token' => 'intraday-test-token']);
    }

    public function test_worker_can_upsert_checkpoint_with_internal_token(): void
    {
        $this->withHeader('X-Intraday-Backfill-Token', 'intraday-test-token')
            ->postJson('/api/internal/intraday-backfill/checkpoints', [
                'symbol' => 'INFY',
                'exchange' => 'NSE',
                'window_start' => '2024-01-01',
                'window_end' => '2024-01-31',
                'status' => 'complete',
                'bars_written' => 9000,
            ])
            ->assertOk()
            ->assertJsonPath('data.symbol', 'INFY')
            ->assertJsonPath('data.status', 'complete');

        $this->withHeader('X-Intraday-Backfill-Token', 'intraday-test-token')
            ->getJson('/api/internal/intraday-backfill/checkpoints')
            ->assertOk()
            ->assertJsonPath('data.0.bars_written', 9000);
    }

    public function test_rejects_missing_token(): void
    {
        $this->postJson('/api/internal/intraday-backfill/checkpoints', [
            'symbol' => 'INFY',
            'status' => 'pending',
        ])->assertStatus(401);
    }
}
