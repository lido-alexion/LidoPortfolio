<?php

namespace Tests\Feature;

use App\Models\AiBudgetLimit;
use App\Models\AiCapability;
use App\Models\AiProviderPath;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Separate PHP processes must contend on the real MySQL ledger lock. */
class AiBudgetConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() === 'mysql') {
            // Commit fixtures for visibility to independent PHP connections.
            // The next RefreshDatabase test must rebuild this isolated schema.
            $this->artisan('migrate:fresh')->assertExitCode(0);
            RefreshDatabaseState::$migrated = false;
        }
    }

    public function test_concurrent_provider_attempts_cannot_oversubscribe_remaining_budget(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Concurrency acceptance requires the --backend MySQL verifier.');
        }
        AiCapability::query()->create(['capability_id' => 'contention', 'owner' => 'test', 'enabled' => true, 'path_order' => ['contention']]);
        AiProviderPath::query()->create(['path_id' => 'contention', 'provider' => 'test', 'model' => 'test', 'enabled' => true, 'config' => ['max_output_tokens' => 1, 'pricing' => ['input_per_million' => 500000, 'output_per_million' => 500000]]]);
        AiBudgetLimit::query()->create(['scope' => 'overall', 'hard_limit' => 1, 'spent' => 0, 'period_started_at' => now()->startOfMonth()]);
        $script = <<<'CODE'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$input = json_decode($argv[1], true);
while (microtime(true) < (float) $argv[2]) { usleep(1000); }
try {
    app(App\Services\AI\AiBudgetReservationService::class)->reserve($input);
    echo 'admitted';
} catch (Illuminate\Validation\ValidationException $error) {
    echo 'denied';
}
CODE;
        $start = (string) (microtime(true) + 1);
        $processes = [];
        for ($i = 0; $i < 2; $i++) {
            $input = ['id' => (string) str()->uuid(), 'request_id' => (string) str()->uuid(), 'capability_id' => 'contention', 'path_id' => 'contention', 'input_token_bound' => 1];
            $process = new Process([PHP_BINARY, '-r', $script, json_encode($input), $start], base_path());
            $process->start();
            $processes[] = $process;
        }
        $outcomes = [];
        foreach ($processes as $process) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $outcomes[] = $process->getOutput();
        }
        sort($outcomes);
        self::assertSame(['admitted', 'denied'], $outcomes);
        self::assertSame(1, DB::table('stox_ai_budget_reservations')->count());
    }
}
