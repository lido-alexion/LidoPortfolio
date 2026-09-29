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

    public function test_worker_can_read_durable_checkpoint_and_control_state(): void
    {
        $this->withHeader('X-Intraday-Backfill-Token', 'intraday-test-token')
            ->postJson('/api/internal/intraday-backfill/checkpoints', [
                'symbol' => 'INFY',
                'exchange' => 'NSE',
                'window_start' => '2024-02-01',
                'window_end' => '2024-02-29',
                'status' => 'complete',
            ])->assertOk();

        $this->withHeader('X-Intraday-Backfill-Token', 'intraday-test-token')
            ->getJson('/api/internal/intraday-backfill/checkpoints?symbol=INFY&exchange=NSE&window_start=2024-02-01&window_end=2024-02-29')
            ->assertOk()
            ->assertJsonPath('data.status', 'complete');

        $this->withHeader('X-Intraday-Backfill-Token', 'intraday-test-token')
            ->getJson('/api/internal/intraday-backfill/control')
            ->assertOk()
            ->assertJsonPath('data.paused', false);
    }

    public function test_invalid_checkpoint_state_and_date_window_are_rejected(): void
    {
        $headers = ['X-Intraday-Backfill-Token' => 'intraday-test-token'];
        $this->withHeaders($headers)->postJson('/api/internal/intraday-backfill/checkpoints', [
            'symbol' => 'INFY', 'status' => 'unknown',
        ])->assertStatus(422);

        $this->withHeaders($headers)->postJson('/api/internal/intraday-backfill/checkpoints', [
            'symbol' => 'INFY', 'window_start' => '2024-02-02', 'window_end' => '2024-02-01', 'status' => 'pending',
        ])->assertStatus(422);
    }
}
