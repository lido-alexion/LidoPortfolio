<?php

namespace Tests\Feature\V8;

use App\Exceptions\MlTrainingRetryScheduledException;
use App\Jobs\MlRetrainJob;
use App\Models\User;
use App\Models\V7\MlTrainingRun;
use App\Services\ML\MlTrainingRunRetryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Tests\TestCase;

class MlTrainingRunRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_transient_failure_schedules_delayed_retry(): void
    {
        Bus::fake();
        config(['ml_lifecycle.retry.max_attempts' => 3]);

        $run = MlTrainingRun::query()->create([
            'horizon' => '3m',
            'status' => 'running',
            'cutoff_date' => now()->toDateString(),
            'configuration' => ['trigger' => 'manual', 'retry' => ['attempt' => 1]],
            'started_at' => now(),
        ]);

        $service = app(MlTrainingRunRetryService::class);

        try {
            $service->scheduleIfTransient($run, '3m', User::factory()->create(), new RuntimeException('ML adapter connection timeout'));
            $this->fail('Expected retry scheduled exception');
        } catch (MlTrainingRetryScheduledException) {
            // expected
        }

        $run->refresh();
        $this->assertSame('queued', $run->status);
        $this->assertSame(2, (int) ($run->configuration['retry']['attempt'] ?? 0));

        Bus::assertDispatched(MlRetrainJob::class, fn (MlRetrainJob $job) => $job->trainingRunId === $run->id);
    }

    public function test_non_transient_failure_does_not_schedule_retry(): void
    {
        Bus::fake();

        $run = MlTrainingRun::query()->create([
            'horizon' => '1m',
            'status' => 'running',
            'cutoff_date' => now()->toDateString(),
            'configuration' => ['trigger' => 'manual'],
            'started_at' => now(),
        ]);

        app(MlTrainingRunRetryService::class)->scheduleIfTransient(
            $run,
            '1m',
            null,
            new RuntimeException('Insufficient point-in-time training dates.'),
        );

        Bus::assertNothingDispatched();
        $this->assertSame('running', $run->fresh()->status);
    }
}
