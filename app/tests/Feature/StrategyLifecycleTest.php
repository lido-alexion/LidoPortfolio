<?php

namespace Tests\Feature;

use App\Models\CapitalLoan;
use App\Models\CapitalRecall;
use App\Models\CapitalRequest;
use App\Models\Holding;
use App\Models\EvaluationRun;
use App\Models\PendingSaleProceeds;
use App\Models\RecallBridgeLoan;
use App\Models\Stock;
use App\Models\TradingOrder;
use App\Models\TradingRecommendation;
use App\Models\TradingStrategy;
use App\Models\TradingStrategyVersion;
use App\Models\User;
use App\Engines\Recommendation\RecommendationGenerationPipeline;
use App\Services\Strategy\StrategyArchiveEligibility;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StrategyLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabling_last_strategy_preserves_existing_financial_work_and_reads_do_not_reenable_it(): void
    {
        [$user, $profile, $strategy, $version, $stock] = $this->strategyContext();
        $recommendation = $this->recommendation($profile, $version, $stock, TradingRecommendation::STATUS_PENDING_EXECUTION);
        $recommendation->forceFill([
            'reserved_amount' => 250,
            'reservation_status' => TradingRecommendation::RESERVATION_RESERVED,
        ])->save();
        $order = TradingOrder::query()->create([
            'profile_id' => $profile->id,
            'recommendation_id' => $recommendation->id,
            'security_id' => $stock->id,
            'side' => 'buy',
            'quantity' => 2,
            'status' => TradingOrder::STATUS_PENDING,
        ]);

        $this->actingAs($user)->postJson("/api/v1/strategy-registry/{$strategy->id}/disable")
            ->assertOk()->assertJsonPath('data.metadata.lifecycle_status', TradingStrategy::STATUS_DISABLED);

        $strategy->refresh();
        $recommendation->refresh();
        $order->refresh();
        $this->assertSame(TradingStrategy::STATUS_DISABLED, $strategy->status);
        $this->assertSame(TradingRecommendation::STATUS_PENDING_EXECUTION, $recommendation->status);
        $this->assertSame(TradingRecommendation::RESERVATION_RESERVED, $recommendation->reservation_status);
        $this->assertSame('250.0000', $recommendation->reserved_amount);
        $this->assertSame(TradingOrder::STATUS_PENDING, $order->status);

        $this->actingAs($user)->getJson('/api/v1/strategy-registry')->assertOk();
        $this->actingAs($user)->getJson('/api/v1/strategy-registry/meta')->assertOk();
        $this->actingAs($user)->getJson('/api/v1/strategy')->assertOk();
        $this->assertSame(TradingStrategy::STATUS_DISABLED, $strategy->fresh()->status);
        $this->assertSame(0, TradingStrategy::query()->where('profile_id', $profile->id)->where('status', TradingStrategy::STATUS_ACTIVE)->count());
        $this->actingAs($user)->postJson('/api/v1/evaluation/runs')
            ->assertUnprocessable()->assertJsonPath('error.code', 'NO_ENABLED_STRATEGY');
        $this->assertSame(0, EvaluationRun::query()->where('profile_id', $profile->id)->count());
    }

    public function test_archive_blockers_follow_exact_profile_and_strategy_ownership_and_positive_balances(): void
    {
        [$user, $profile, $strategy, $version, $stock] = $this->strategyContext();
        $eligibility = app(StrategyArchiveEligibility::class);
        $this->assertSame([], $eligibility->blockers($profile, $strategy));
        $foreignUser = User::factory()->create();
        $foreignProfile = $this->defaultPortfolioFor($foreignUser);
        $this->recommendation($foreignProfile, $version, $stock, TradingRecommendation::STATUS_PENDING_REVIEW);
        $this->assertSame([], $eligibility->blockers($profile, $strategy));
        $sibling = TradingStrategy::query()->create([
            'profile_id' => $profile->id, 'name' => 'Sibling', 'slug' => 'sibling-lifecycle',
            'status' => TradingStrategy::STATUS_DISABLED,
        ]);

        $holding = Holding::query()->create([
            'profile_id' => $profile->id, 'stock_id' => $stock->id, 'strategy_id' => $strategy->id,
            'owner_key' => Holding::ownerKeyFor((int) $strategy->id), 'quantity' => 2,
            'avg_buy_price' => 100, 'invested_amount' => 200, 'target_amount' => 200,
        ]);
        $this->assertContains('owned_holdings', $this->blockerCodes($eligibility, $profile, $strategy));
        $this->postJson("/api/v1/strategy-registry/{$strategy->id}/archive", ['reason' => 'Attempt retirement'])
            ->assertStatus(409)->assertJsonPath('meta.blockers.0.code', 'owned_holdings');
        $this->assertSame(TradingStrategy::STATUS_ACTIVE, $strategy->fresh()->status);
        $holding->forceFill(['quantity' => 0])->save();
        $this->assertNotContains('owned_holdings', $this->blockerCodes($eligibility, $profile, $strategy));

        $recommendation = $this->recommendation($profile, $version, $stock, TradingRecommendation::STATUS_PENDING_REVIEW);
        $codes = $this->blockerCodes($eligibility, $profile, $strategy);
        $this->assertContains('actionable_recommendations', $codes);
        $recommendation->forceFill([
            'status' => TradingRecommendation::STATUS_PENDING_EXECUTION,
            'reserved_amount' => 50,
            'reservation_status' => TradingRecommendation::RESERVATION_RESERVED,
        ])->save();
        TradingOrder::query()->create([
            'profile_id' => $profile->id, 'recommendation_id' => $recommendation->id,
            'security_id' => $stock->id, 'side' => 'buy', 'quantity' => 1,
            'status' => TradingOrder::STATUS_PENDING,
        ]);
        $codes = $this->blockerCodes($eligibility, $profile, $strategy);
        $this->assertContains('pending_executions', $codes);
        $this->assertContains('orders', $codes);
        $this->assertContains('reserved_funds', $codes);

        $request = CapitalRequest::query()->create([
            'profile_id' => $profile->id, 'borrower_strategy_id' => $strategy->id,
            'recommendation_id' => $recommendation->id, 'amount' => 100,
            'status' => CapitalRequest::STATUS_DISPLAYED,
        ]);
        $loan = CapitalLoan::query()->create([
            'profile_id' => $profile->id, 'capital_request_id' => $request->id,
            'borrower_strategy_id' => $strategy->id, 'lender_strategy_id' => $sibling->id,
            'principal' => 100, 'outstanding' => 40, 'committed_at' => now(), 'status' => CapitalLoan::STATUS_OUTSTANDING,
        ]);
        $recall = CapitalRecall::query()->create([
            'profile_id' => $profile->id, 'loan_id' => $loan->id,
            'lender_strategy_id' => $loan->lender_strategy_id, 'borrower_strategy_id' => $strategy->id,
            'kind' => CapitalRecall::KIND_FULL, 'recall_amount' => 40, 'outstanding_recall_amount' => 40,
            'state' => CapitalRecall::STATE_SETTLEMENT, 'requested_at' => now(),
        ]);
        $bridge = RecallBridgeLoan::query()->create([
            'profile_id' => $profile->id, 'capital_recall_id' => $recall->id,
            'borrower_strategy_id' => $strategy->id, 'lender_strategy_id' => $loan->lender_strategy_id,
            'principal' => 20, 'outstanding' => 20, 'committed_at' => now(), 'status' => RecallBridgeLoan::STATUS_OUTSTANDING,
        ]);
        PendingSaleProceeds::query()->create([
            'profile_id' => $profile->id, 'strategy_id' => $strategy->id,
            'capital_recall_id' => $recall->id, 'amount' => 10,
            'sold_at' => now(), 'available_at' => now()->addDay(), 'status' => PendingSaleProceeds::STATUS_PENDING,
        ]);
        $codes = $this->blockerCodes($eligibility, $profile, $strategy);
        $this->assertContains('capital_requests', $codes);
        $this->assertContains('capital_loans', $codes);
        $this->assertContains('capital_recalls', $codes);
        $this->assertContains('settlements', $codes);
        $loan->forceFill(['outstanding' => 0])->save();
        $bridge->forceFill(['outstanding' => 0])->save();
        PendingSaleProceeds::query()->where('strategy_id', $strategy->id)->update(['amount' => 0]);
        CapitalRecall::query()->whereKey($recall->id)->update(['outstanding_recall_amount' => 0]);
        $settledCodes = $this->blockerCodes($eligibility, $profile, $strategy);
        $this->assertNotContains('capital_loans', $settledCodes);
        $this->assertNotContains('capital_recalls', $settledCodes);
        $this->assertNotContains('settlements', $settledCodes);

        Holding::query()->create([
            'profile_id' => $profile->id, 'stock_id' => $stock->id, 'strategy_id' => $sibling->id,
            'owner_key' => Holding::ownerKeyFor((int) $sibling->id), 'quantity' => 1,
            'avg_buy_price' => 100, 'invested_amount' => 100, 'target_amount' => 100,
        ]);
        $this->assertNotContains('owned_holdings', $this->blockerCodes($eligibility, $profile, $strategy));
    }

    public function test_archive_requires_reason_is_read_only_and_restore_never_enables(): void
    {
        [$user, $profile, $strategy] = $this->strategyContext();
        $this->actingAs($user)->postJson("/api/v1/strategy-registry/{$strategy->id}/archive")
            ->assertUnprocessable();
        $archived = $this->actingAs($user)->postJson("/api/v1/strategy-registry/{$strategy->id}/archive", [
            'reason' => 'No longer used by this portfolio',
        ])->assertOk()->json('data');
        $strategy->refresh();
        $this->assertSame(TradingStrategy::STATUS_ARCHIVED, $strategy->status);
        $this->assertSame('No longer used by this portfolio', $strategy->archive_reason);
        $this->assertNotNull($strategy->archived_at);
        $this->assertSame(TradingStrategy::STATUS_ARCHIVED, $archived['metadata']['lifecycle_status']);

        $this->actingAs($user)->postJson("/api/v1/strategy-registry/{$strategy->id}/activate")->assertUnprocessable();
        $this->actingAs($user)->postJson("/api/v1/strategy-registry/{$strategy->id}/restore")
            ->assertOk()->assertJsonPath('data.metadata.lifecycle_status', TradingStrategy::STATUS_DISABLED);
        $strategy->refresh();
        $this->assertSame(TradingStrategy::STATUS_DISABLED, $strategy->status);
        $this->assertSame('No longer used by this portfolio', $strategy->archive_reason);

        $this->actingAs($user)->getJson('/api/v1/strategy-registry/'.$strategy->id)
            ->assertOk()->assertJsonPath('data.metadata.lifecycle_status', TradingStrategy::STATUS_DISABLED);
        $this->assertSame(0, TradingStrategy::query()->where('profile_id', $profile->id)->where('status', TradingStrategy::STATUS_ACTIVE)->count());
    }

    public function test_generation_skips_a_strategy_paused_after_enabled_selection_was_read(): void
    {
        [$user, $profile, $strategy] = $this->strategyContext();
        $pausedBetweenSelectionAndWrite = false;
        DB::listen(function (QueryExecuted $query) use ($strategy, &$pausedBetweenSelectionAndWrite): void {
            if ($pausedBetweenSelectionAndWrite
                || ! str_contains($query->sql, 'portfolio_tos_strategies')
                || ! in_array(TradingStrategy::STATUS_ACTIVE, $query->bindings, true)) {
                return;
            }
            $pausedBetweenSelectionAndWrite = true;
            TradingStrategy::query()->whereKey($strategy->id)->update(['status' => TradingStrategy::STATUS_DISABLED]);
        });

        $result = app(RecommendationGenerationPipeline::class)->run($profile, null, [(int) $strategy->id]);

        $this->assertTrue($pausedBetweenSelectionAndWrite, 'Fixture must pause after generation reads the enabled strategy set.');
        $this->assertSame(TradingStrategy::STATUS_DISABLED, $strategy->fresh()->status);
        $this->assertSame([], $result['recommendations']);
        $this->assertSame(0, TradingRecommendation::query()->where('profile_id', $profile->id)->count());
    }

    private function strategyContext(): array
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $this->actingAs($user);
        $data = $this->getJson('/api/v1/strategy')->assertOk()->json('data');
        $strategy = TradingStrategy::query()->findOrFail($data['id']);
        $version = TradingStrategyVersion::query()->findOrFail($data['version_id']);
        $stock = Stock::query()->create(['symbol' => 'STR14', 'exchange' => 'NSE', 'name' => 'Lifecycle Test', 'is_active' => true]);

        return [$user, $profile, $strategy, $version, $stock];
    }

    private function recommendation($profile, $version, Stock $stock, string $status): TradingRecommendation
    {
        return TradingRecommendation::query()->create([
            'profile_id' => $profile->id,
            'security_id' => $stock->id,
            'strategy_version_id' => $version->id,
            'recommendation_type' => TradingRecommendation::ACTION_OPEN_POSITION,
            'status' => $status,
            'priority' => 1,
            'strategy_score' => 70,
            'confidence' => 0.7,
            'risk_level' => TradingRecommendation::RISK_MEDIUM,
            'generated_at' => now(),
        ]);
    }

    private function blockerCodes(StrategyArchiveEligibility $eligibility, $profile, TradingStrategy $strategy): array
    {
        return array_column($eligibility->blockers($profile, $strategy), 'code');
    }
}
