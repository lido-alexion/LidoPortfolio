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
        $this->actingAs($user)->postJson('/api/help-feedback', ['topic_id' => '__query__', 'event' => 'no_match', 'query' => 'unmatched test term'])->assertOk();
        $this->actingAs($user)->postJson('/api/help-feedback', ['topic_id' => '__query__', 'event' => 'weak_match', 'query' => 'unmatched test term'])->assertOk();
        $this->assertDatabaseHas('portfolio_help_feedback_aggregates', ['topic_id' => 'SCR-01', 'selected_count' => 1, 'helpful_count' => 1]);
        $aggregate = HelpFeedbackAggregate::where('topic_id', '__query__')->firstOrFail();
        $this->assertSame(1, $aggregate->no_match_count);
        $this->assertSame(1, $aggregate->weak_match_count);
        $this->assertNotSame('unmatched test term', $aggregate->query_digest);
        $this->assertFalse(array_key_exists('query', (new HelpFeedbackAggregate())->getAttributes()));
    }

    public function test_help_diagnostics_require_authentication(): void
    {
        $this->postJson('/api/help-feedback', ['topic_id' => '__search__', 'event' => 'no_match'])->assertUnauthorized();
    }
}
