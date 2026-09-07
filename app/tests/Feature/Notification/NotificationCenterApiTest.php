<?php

namespace Tests\Feature\Notification;

use App\Models\User;
use App\Services\Notification\NotificationPublisher;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationCenterApiTest extends TestCase
{
    use RefreshDatabase;

    private NotificationPublisher $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->publisher = app(NotificationPublisher::class);
    }

    public function test_list_is_account_scoped_and_opening_detail_does_not_mark_read(): void
    {
        $investor = User::factory()->create(['is_admin' => false]);
        $other = User::factory()->create(['is_admin' => false]);
        $mine = $this->publisher->publishEvent([$investor], $this->content('info'))->recipients->sole();
        $theirs = $this->publisher->publishEvent([$other], $this->content('critical'))->recipients->sole();

        $this->actingAs($investor)->getJson('/api/notification-center')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('meta.unread_count', 1);

        $this->actingAs($investor)->getJson("/api/notification-center/{$mine->id}")
            ->assertOk()
            ->assertJsonPath('data.attention_state', 'unread')
            ->assertJsonCount(1, 'data.timeline');
        $this->actingAs($investor)->getJson("/api/notification-center/{$theirs->id}")->assertNotFound();
    }

    public function test_read_actions_change_attention_only(): void
    {
        $investor = User::factory()->create(['is_admin' => false]);
        $first = $this->publisher->publishCondition('first', [$investor], $this->content('action_required'))->recipients->sole();
        $second = $this->publisher->publishCondition('second', [$investor], $this->content('critical'))->recipients->sole();

        $this->actingAs($investor)->postJson("/api/notification-center/{$first->id}/read")
            ->assertOk()
            ->assertJsonPath('data.attention_state', 'read')
            ->assertJsonPath('data.condition_state', 'active');
        $this->actingAs($investor)->postJson('/api/notification-center/mark-all-read')
            ->assertOk()
            ->assertJsonPath('data.updated', 1);

        $this->assertDatabaseHas('portfolio_recipient_notifications', [
            'id' => $second->id,
            'attention_state' => 'read',
            'condition_state' => 'active',
        ]);
    }

    public function test_views_and_counts_follow_frozen_attention_and_condition_rules(): void
    {
        $investor = User::factory()->create(['is_admin' => false]);
        $this->publisher->publishEvent([$investor], $this->content('info'));
        $critical = $this->publisher->publishCondition('critical', [$investor], $this->content('critical'));
        $resolved = $this->publisher->publishCondition('resolved', [$investor], $this->content('action_required'));
        $this->publisher->resolveCondition('resolved');

        $this->actingAs($investor)->getJson('/api/notification-center?view=needs_attention')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $critical->recipients->sole()->id)
            ->assertJsonPath('meta.active_critical_count', 1);
        $this->actingAs($investor)->getJson('/api/notification-center?view=resolved')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $resolved->recipients->sole()->id);
    }

    public function test_admin_uses_same_account_api_without_acquiring_a_portfolio(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $notification = $this->publisher->publishEvent([$admin], $this->content('info', 'admin'))->recipients->sole();

        $this->actingAs($admin)->getJson('/api/notification-center')
            ->assertOk()
            ->assertJsonPath('data.0.id', $notification->id);
        $this->assertDatabaseMissing('portfolio_profiles', ['user_id' => $admin->id]);
    }

    private function content(string $severity, string $audience = 'investor'): array
    {
        return [
            'notification_type' => 'test.api',
            'audience' => $audience,
            'severity' => $severity,
            'title' => 'Test notification',
            'message' => 'Notification Center API test.',
        ];
    }
}
