<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AlertPolicy;
use App\Models\Holding;
use App\Models\Stock;
use App\Models\User;
use App\Services\Alerts\AlertPolicyEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AlertPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    public function test_create_policy_and_generate_alert_for_holdings(): void
    {
        $user = User::query()->create([
            'name' => 'Policy User',
            'email' => 'pol-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'POL1',
            'exchange' => 'NSE',
            'name' => 'Policy Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        \App\Models\Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 10,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2026-01-01',
        ]);

        $this->actingAs($user)
            ->withHeader('X-Profile-Id', (string) $profile->id)
            ->postJson('/api/alert-policies', [
                'name' => 'Below avg buy',
                'stock_universe' => 'holdings',
                'condition_column' => 'avg_buy_price',
                'condition_operator' => 'gt',
                'compare_type' => 'constant',
                'compare_constant' => 50,
                'message_template' => '{{symbol}} avg buy is {{avg_buy_price}}',
                'action_type' => 'track',
                'context_columns' => ['quantity', 'invested_amount'],
                'is_enabled' => true,
            ])
            ->assertCreated();

        $result = app(AlertPolicyEvaluationService::class)->evaluateProfile($profile);
        $this->assertSame(1, $result['generated']);

        $policy = AlertPolicy::query()->where('profile_id', $profile->id)->first();
        $instanceKey = app(AlertPolicyEvaluationService::class)
            ->buildInstanceKey($user->id, $profile->id, $stock->id, $policy->id);

        $alert = Alert::query()->where('instance_key', $instanceKey)->first();
        $this->assertNotNull($alert);
        $this->assertSame('policy', $alert->alert_type);
        $this->assertStringContainsString('POL1', $alert->message);
        $this->assertSame('Track', $alert->action_suggested);
        $this->assertNotNull($alert->condition_display);

        $duplicate = app(AlertPolicyEvaluationService::class)->evaluateProfile($profile);
        $this->assertSame(0, $duplicate['generated']);
    }

    public function test_policy_name_must_be_unique_per_portfolio(): void
    {
        $user = User::query()->create([
            'name' => 'Unique Policy User',
            'email' => 'uniq-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'UNQ1',
            'exchange' => 'NSE',
            'name' => 'Unique Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        \App\Models\Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 1,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2026-01-01',
        ]);

        $payload = [
            'name' => 'Duplicate name',
            'condition_column' => 'quantity',
            'condition_operator' => 'gt',
            'compare_type' => 'constant',
            'compare_constant' => 1,
            'message_template' => 'Test {{symbol}}',
            'action_type' => 'sell',
        ];

        $this->actingAs($user)
            ->withHeader('X-Profile-Id', (string) $profile->id)
            ->postJson('/api/alert-policies', $payload)
            ->assertCreated();

        $this->actingAs($user)
            ->withHeader('X-Profile-Id', (string) $profile->id)
            ->postJson('/api/alert-policies', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_create_policy_rejects_invalid_message_template(): void
    {
        $user = User::query()->create([
            'name' => 'Invalid Template User',
            'email' => 'badtmpl-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'BAD1',
            'exchange' => 'NSE',
            'name' => 'Bad Template Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        \App\Models\Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 1,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2026-01-01',
        ]);

        $this->actingAs($user)
            ->withHeader('X-Profile-Id', (string) $profile->id)
            ->postJson('/api/alert-policies', [
                'name' => 'Broken message',
                'condition_column' => 'quantity',
                'condition_operator' => 'gt',
                'compare_type' => 'constant',
                'compare_constant' => 0,
                'message_template' => 'Broken <<{{avg_buy_price}} * >>',
                'action_type' => 'track',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['message_template']);
    }

    public function test_manual_evaluate_endpoint(): void
    {
        $user = User::query()->create([
            'name' => 'Evaluate User',
            'email' => 'eval-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);

        AlertPolicy::query()->create([
            'profile_id' => $profile->id,
            'name' => 'Qty check',
            'stock_universe' => 'holdings',
            'condition_column' => 'quantity',
            'condition_operator' => 'gt',
            'compare_type' => 'constant',
            'compare_constant' => 0,
            'message_template' => 'Has qty',
            'action_type' => 'track',
            'context_columns' => [],
            'is_enabled' => true,
        ]);

        $this->actingAs($user)
            ->withHeader('X-Profile-Id', (string) $profile->id)
            ->postJson('/api/alert-policies/evaluate')
            ->assertOk()
            ->assertJsonStructure([
                'message',
                'data' => [
                    'policies',
                    'generated',
                    'skipped',
                    'holdings_checked',
                    'details',
                    'details_truncated',
                ],
            ]);
    }

    public function test_evaluate_report_includes_condition_not_met_detail(): void
    {
        $user = User::query()->create([
            'name' => 'Report User',
            'email' => 'rpt-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create([
            'symbol' => 'RPT1',
            'exchange' => 'NSE',
            'name' => 'Report Stock',
            'is_active' => true,
            'is_benchmark' => false,
        ]);
        \App\Models\Transaction::query()->create([
            'profile_id' => $profile->id,
            'stock_id' => $stock->id,
            'type' => 'buy',
            'quantity' => 5,
            'price' => 100,
            'fees' => 0,
            'transaction_date' => '2026-01-01',
        ]);

        AlertPolicy::query()->create([
            'profile_id' => $profile->id,
            'name' => 'Impossible qty',
            'stock_universe' => 'holdings',
            'condition_column' => 'quantity',
            'condition_operator' => 'gt',
            'compare_type' => 'constant',
            'compare_constant' => 99999,
            'message_template' => 'Never',
            'action_type' => 'track',
            'context_columns' => [],
            'is_enabled' => true,
        ]);

        $result = app(AlertPolicyEvaluationService::class)->evaluateProfile($profile);

        $this->assertSame(0, $result['generated']);
        $this->assertNotEmpty($result['details']);
        $this->assertSame('condition_not_met', $result['details'][0]['outcome']);
        $this->assertSame('RPT1', $result['details'][0]['stock_symbol']);
    }
}
