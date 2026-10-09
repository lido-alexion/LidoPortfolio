<?php

namespace Tests\Feature;

use App\Jobs\DeliverExportNotificationOutbox;
use App\Models\ExportArtifact;
use App\Models\ExportNotificationOutbox;
use App\Models\NotificationChannelSetting;
use App\Models\NotificationEmailDestination;
use App\Models\NotificationSource;
use App\Models\User;
use App\Services\Notification\NotificationPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class V9Data001ExportNotificationOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_notification_delivery_remains_durable_and_is_safe_to_retry(): void
    {
        Queue::fake();
        [$artifact, $event] = $this->readyArtifactWithEvent();
        $publisher = Mockery::mock(NotificationPublisher::class);
        $publisher->shouldReceive('publishEvent')->once()->andThrow(new RuntimeException('temporary notification failure'));

        try {
            (new DeliverExportNotificationOutbox($event->id))->handle($publisher);
            $this->fail('Expected the temporary notification failure to be rethrown for queue retry.');
        } catch (RuntimeException $error) {
            $this->assertSame('temporary notification failure', $error->getMessage());
        }

        $event->refresh();
        $this->assertNull($event->delivered_at);
        $this->assertSame(1, $event->attempts);
        $this->assertSame('RuntimeException', $event->last_error_code);
        $this->assertTrue($event->next_attempt_at->isFuture());
        $this->assertSame('ready', $artifact->fresh()->status);

        $event->update(['next_attempt_at' => now()->subSecond(), 'last_dispatched_at' => null]);
        Queue::fake();
        $this->artisan('portfolio:dispatch-export-notification-outbox')->assertExitCode(0);
        Queue::assertPushed(DeliverExportNotificationOutbox::class, fn ($job) => $job->outboxId === $event->id);

        $this->app->forgetInstance(NotificationPublisher::class);
        $job = new DeliverExportNotificationOutbox($event->id);
        $job->handle(app(NotificationPublisher::class));
        $job->handle(app(NotificationPublisher::class));

        $this->assertNotNull($event->fresh()->delivered_at);
        $this->assertDatabaseCount('portfolio_notification_sources', 1);
        $this->assertDatabaseCount('portfolio_recipient_notifications', 1);
        $this->assertDatabaseHas('portfolio_notification_sources', ['notification_type' => 'export.completed']);
    }

    public function test_completion_email_uses_optional_export_preferences(): void
    {
        Queue::fake();
        [$defaultUserArtifact, $defaultUserEvent] = $this->readyArtifactWithEvent();
        (new DeliverExportNotificationOutbox($defaultUserEvent->id))->handle(app(NotificationPublisher::class));
        $this->assertDatabaseCount('portfolio_notification_deliveries', 0);

        $user = User::factory()->create(['email_verified_at' => now()]);
        NotificationChannelSetting::query()->create([
            'user_id' => $user->id,
            'channel' => 'email',
            'enabled' => true,
            'configuration' => [
                'optional_email_preferences' => [
                    'enabled' => true,
                    'categories' => ['export' => true],
                    'modes' => ['export' => 'immediate'],
                ],
            ],
            'health_status' => 'healthy',
            'verified_at' => now(),
        ]);
        NotificationEmailDestination::query()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'is_account_email' => true,
            'verified_at' => now(),
        ]);
        [, $optedInEvent] = $this->readyArtifactWithEvent($user);

        (new DeliverExportNotificationOutbox($optedInEvent->id))->handle(app(NotificationPublisher::class));

        $this->assertDatabaseHas('portfolio_notification_deliveries', [
            'channel' => 'email',
            'status' => 'queued',
        ]);
        $this->assertNotNull($optedInEvent->fresh()->delivered_at);
    }

    /** @return array{ExportArtifact, ExportNotificationOutbox} */
    private function readyArtifactWithEvent(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $token = (string) Str::uuid();
        $artifact = ExportArtifact::query()->create([
            'user_id' => $user->id,
            'token' => $token,
            'dataset' => 'portfolio-snapshots',
            'format' => 'csv',
            'path' => 'exports/'.$user->id.'/'.$token.'.csv',
            'status' => 'ready',
            'expires_at' => now()->addDay(),
        ]);
        $event = ExportNotificationOutbox::query()->create([
            'artifact_id' => $artifact->id,
            'event_type' => 'completed',
        ]);

        return [$artifact, $event];
    }
}
