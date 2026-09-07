<?php

namespace Tests\Feature\Notification;

use App\Models\NotificationSource;
use App\Models\User;
use App\Services\Notification\NotificationPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationPublisherTest extends TestCase
{
    use RefreshDatabase;

    private NotificationPublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->publisher = app(NotificationPublisher::class);
    }

    public function test_active_condition_deduplicates_without_resetting_read_state(): void
    {
        $investor = $this->user();
        $first = $this->publisher->publishCondition('broker:down:'.$investor->id, [$investor], $this->content('action_required'));
        $recipient = $first->recipients->sole();
        $this->publisher->markRead($recipient);

        $second = $this->publisher->publishCondition('broker:down:'.$investor->id, [$investor], [
            ...$this->content('action_required'),
            'message' => 'Still unavailable.',
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, $second->occurrence_count);
        $this->assertSame('read', $second->recipients->sole()->attention_state);
        $this->assertSame(['detected', 'redetected'], $second->occurrences->pluck('activity_type')->all());
    }

    public function test_critical_escalation_makes_all_recipient_instances_unread(): void
    {
        $one = $this->user();
        $two = $this->user();
        $source = $this->publisher->publishCondition('market:data:stale', [$one, $two], $this->content('action_required', 'both'));
        $source->recipients->each(fn ($recipient) => $this->publisher->markRead($recipient));

        $escalated = $this->publisher->publishCondition('market:data:stale', [$one, $two], $this->content('critical', 'both'));

        $this->assertSame(['unread'], $escalated->recipients->pluck('attention_state')->unique()->values()->all());
        $this->assertSame('severity_escalated', $escalated->occurrences->last()->activity_type);
    }

    public function test_resolution_preserves_attention_and_recurrence_creates_new_source(): void
    {
        $investor = $this->user();
        $source = $this->publisher->publishCondition('kite:disconnected', [$investor], $this->content('critical'));
        $this->publisher->markRead($source->recipients->sole());

        $resolved = $this->publisher->resolveCondition('kite:disconnected');
        $this->assertSame('resolved', $resolved->condition_state);
        $this->assertSame('read', $resolved->recipients->sole()->attention_state);

        $recurrence = $this->publisher->publishCondition('kite:disconnected', [$investor], $this->content('critical'));
        $this->assertNotSame($source->id, $recurrence->id);
        $this->assertSame('unread', $recurrence->recipients->sole()->attention_state);
        $this->assertSame(2, NotificationSource::query()->where('condition_key', 'kite:disconnected')->count());
    }

    public function test_audience_fanout_never_crosses_role_boundary(): void
    {
        $investor = $this->user();
        $admin = $this->user(true);

        $source = $this->publisher->publishEvent([$investor, $admin], $this->content('info', 'admin'));

        $this->assertSame([$admin->id], $source->recipients->pluck('user_id')->all());
        $this->assertSame('na', $source->condition_state);
    }

    private function user(bool $admin = false): User
    {
        $user = User::query()->create([
            'name' => $admin ? 'Admin' : 'Investor',
            'email' => Str::random(12).'@example.com',
            'password' => Hash::make('password'),
        ]);
        $user->forceFill(['is_admin' => $admin])->save();

        return $user->fresh();
    }

    private function content(string $severity, string $audience = 'investor'): array
    {
        return [
            'notification_type' => 'test.condition',
            'audience' => $audience,
            'severity' => $severity,
            'title' => 'Attention needed',
            'message' => 'A test condition exists.',
            'context' => ['safe_id' => 42],
        ];
    }
}
