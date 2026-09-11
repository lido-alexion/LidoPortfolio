<?php

namespace Tests\Feature;

use App\Models\ExecutionSafetyEvent;
use App\Models\SystemLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V6AdminAuditExplorerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_read_curated_audit_events_and_members_cannot(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $this->defaultPortfolioFor($admin);
        $profile = $this->defaultPortfolioFor($member);

        ExecutionSafetyEvent::query()->create([
            'user_id' => $member->id,
            'actor_user_id' => $member->id,
            'event' => 'execution.halted',
            'provider' => 'kite',
            'status' => 'completed',
            'context' => ['reason' => 'test', 'secret' => 'hidden'],
            'created_at' => now(),
        ]);
        SystemLog::query()->create([
            'category' => 'LiveBrokerExecutionService',
            'level' => 'info',
            'message' => 'Broker order submitted',
            'context' => [
                'event' => 'execution.broker_submitted',
                'user_id' => $member->id,
                'profile_id' => $profile->id,
                'broker_order_id' => 'abc',
            ],
            'created_at' => now()->subMinute(),
        ]);

        $this->actingAs($member)->withProfileHeader($member, $profile)
            ->getJson('/api/admin/audit')
            ->assertForbidden();

        $response = $this->actingAs($admin)->withProfileHeader($admin)
            ->getJson('/api/admin/audit?user_id='.$member->id)
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.source', 'safety')
            ->assertJsonMissingPath('data.0.context.secret');

        $this->assertSame('execution.halted', $response->json('data.0.event'));
    }

    public function test_admin_can_export_raw_authorized_audit_csv(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $this->defaultPortfolioFor($admin);
        $this->defaultPortfolioFor($member);

        ExecutionSafetyEvent::query()->create([
            'user_id' => $member->id,
            'actor_user_id' => $member->id,
            'event' => 'execution.recovered',
            'provider' => 'kite',
            'status' => 'completed',
            'context' => ['reason' => 'verified'],
            'created_at' => now(),
        ]);

        $response = $this->actingAs($admin)->withProfileHeader($admin)
            ->get('/api/admin/audit/export?source=safety&user_id='.$member->id)
            ->assertOk();

        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('execution.recovered', $response->streamedContent());
    }
}
