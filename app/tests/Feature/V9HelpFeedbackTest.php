<?php

namespace Tests\Feature;

use App\Models\HelpFeedbackAggregate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V9HelpFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_help_feedback_is_stored_as_aggregate_without_raw_query_text(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $this->actingAs($user)->postJson('/api/help-feedback', ['topic_id' => 'SCR-01', 'event' => 'selected'])->assertOk();
        $this->actingAs($user)->postJson('/api/help-feedback', ['topic_id' => 'SCR-01', 'event' => 'helpful'])->assertOk();
        $this->assertDatabaseHas('portfolio_help_feedback_aggregates', ['topic_id' => 'SCR-01', 'selected_count' => 1, 'helpful_count' => 1]);
        $this->assertFalse(array_key_exists('query', (new HelpFeedbackAggregate())->getAttributes()));
    }
}
