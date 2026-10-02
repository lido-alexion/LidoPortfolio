<?php

namespace Tests\Feature;

use App\Models\AiBudgetLimit;
use App\Models\AiCapability;
use App\Models\AiProviderPath;
use App\Services\AI\AiBudgetReservationService;
use App\Services\AI\AiInferenceAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AiBudgetReservationTest extends TestCase
{
    use RefreshDatabase;

    private function setupLedger(): array
    {
        AiCapability::query()->updateOrCreate(['capability_id' => 'test'], ['owner' => 'test', 'enabled' => true, 'path_order' => ['one', 'two']]);
        foreach (['one', 'two'] as $path) {
            AiProviderPath::query()->create(['path_id' => $path, 'provider' => 'test', 'model' => 'test', 'config' => ['max_output_tokens' => 100, 'pricing' => ['input_per_million' => 1000, 'output_per_million' => 2000]]]);
        }
        AiBudgetLimit::query()->create(['scope' => 'overall', 'hard_limit' => .35, 'period_started_at' => now()->startOfMonth()]);
        return ['id' => (string) str()->uuid(), 'request_id' => (string) str()->uuid(), 'capability_id' => 'test', 'path_id' => 'one', 'input_token_bound' => 100, 'user_id' => 42];
    }

    public function test_private_contract_auth_validation_and_deferred_audit_ack(): void
    {
        $input = $this->setupLedger() + ['provider' => 'test', 'model' => 'test'];
        config(['ai_runtime.shared_secret' => 'test-runtime-key']);
        $this->postJson('/api/internal/v1/ai-runtime/reservations', $input)->assertUnauthorized();
        $this->withHeader('X-StoX-AI-Service-Key', 'test-runtime-key');
        $this->postJson('/api/internal/v1/ai-runtime/reservations', array_replace($input, ['input_token_bound' => -1]))->assertUnprocessable();
        $this->postJson('/api/internal/v1/ai-runtime/reservations', $input)->assertOk()->assertJsonPath('data.id', $input['id']);
        $event = ['request_id' => $input['request_id'], 'capability' => 'test', 'status' => 'success'];
        $this->postJson('/api/internal/v1/ai-runtime/inference-events', $event)->assertUnprocessable();
        $this->postJson('/api/internal/v1/ai-runtime/settlements', ['id' => $input['id'], 'usage' => ['input_tokens' => 10, 'output_tokens' => 20, 'estimated_cost' => 999]])->assertOk()->assertJsonPath('data.cost', .05);
        $first = $this->postJson('/api/internal/v1/ai-runtime/inference-events', $event)->assertOk()->json('data.event_id');
        $this->postJson('/api/internal/v1/ai-runtime/inference-events', $event)->assertOk()->assertJsonPath('data.event_id', $first);
    }

    public function test_unknown_usage_retains_maximum_and_actual_overrun_is_not_hidden(): void
    {
        $input = $this->setupLedger();
        $service = app(AiBudgetReservationService::class);
        $service->reserve($input);
        self::assertEqualsWithDelta(.3, $service->settle($input['id'], null)['cost'], 1e-8);
        AiBudgetLimit::query()->where('scope', 'overall')->update(['hard_limit' => 1]);
        $input['id'] = (string) str()->uuid();
        $service->reserve($input);
        $service->settle($input['id'], ['input_tokens' => 100, 'output_tokens' => 500]);
        self::assertEqualsWithDelta(1.4, (float) AiBudgetLimit::query()->where('scope', 'overall')->value('spent'), 1e-8);
    }

    public function test_capability_and_user_hard_limits_are_both_admission_constraints(): void
    {
        $input = $this->setupLedger();
        foreach (['capability:test', 'user:42'] as $scope) {
            $limit = AiBudgetLimit::query()->create(['scope' => $scope, 'hard_limit' => .1, 'period_started_at' => now()->startOfMonth()]);
            try {
                app(AiBudgetReservationService::class)->reserve($input);
                self::fail('Scope was oversubscribed');
            } catch (ValidationException $error) {
                self::assertStringContainsString('hard_budget_exhausted:'.$scope, $error->getMessage());
            }
            $limit->update(['hard_limit' => null]);
        }
        self::assertSame(0, DB::table('stox_ai_budget_reservations')->count());
    }

    public function test_active_reservation_survives_service_restart_and_prevents_oversubscription(): void
    {
        $input = $this->setupLedger();
        $first = (new AiBudgetReservationService)->reserve($input);
        self::assertEqualsWithDelta(.3, $first['reserved_cost'], 1e-8);
        $input['id'] = (string) str()->uuid();
        $this->expectException(ValidationException::class);
        (new AiBudgetReservationService)->reserve($input);
    }

    public function test_pricing_settlement_and_audit_delivery_are_idempotent(): void
    {
        $input = $this->setupLedger();
        $service = app(AiBudgetReservationService::class);
        $service->reserve($input);
        $usage = ['input_tokens' => 10, 'output_tokens' => 20, 'estimated_cost' => 99999];
        $service->settle($input['id'], $usage);
        $service->settle($input['id'], $usage);
        self::assertEqualsWithDelta(.05, (float) AiBudgetLimit::query()->where('scope', 'overall')->value('spent'), 1e-8);
        $event = ['request_id' => $input['request_id'], 'capability' => 'test', 'status' => 'success', 'usage' => $usage];
        $audit = app(AiInferenceAuditService::class);
        self::assertSame($audit->record($event)->id, $audit->record($event)->id);
        self::assertEqualsWithDelta(.05, (float) $audit->record($event)->estimated_cost, 1e-8);
    }

    public function test_expired_unknown_execution_charges_maximum_until_actual_settlement(): void
    {
        $input = $this->setupLedger();
        $service = app(AiBudgetReservationService::class);
        $service->reserve($input);
        DB::table('stox_ai_budget_reservations')->update(['expires_at' => now()->subSecond()]);
        $service->reconcile();
        $service->reconcile();
        self::assertEqualsWithDelta(.3, (float) AiBudgetLimit::query()->where('scope', 'overall')->value('spent'), 1e-8);
        $service->settle($input['id'], ['input_tokens' => 10, 'output_tokens' => 20]);
        self::assertEqualsWithDelta(.05, (float) AiBudgetLimit::query()->where('scope', 'overall')->value('spent'), 1e-8);
    }

    public function test_late_usage_reconciles_a_delivered_unknown_settlement_once(): void
    {
        $input = $this->setupLedger();
        $service = app(AiBudgetReservationService::class);
        $service->reserve($input);
        $service->settle($input['id'], null);
        $usage = ['input_tokens' => 10, 'output_tokens' => 20];
        $service->settle($input['id'], $usage);
        $service->settle($input['id'], $usage);
        $service->settle($input['id'], null);
        self::assertEqualsWithDelta(.05, (float) AiBudgetLimit::query()->where('scope', 'overall')->value('spent'), 1e-8);
    }

    public function test_path_exhaustion_keeps_fallback_eligible(): void
    {
        $input = $this->setupLedger();
        AiBudgetLimit::query()->create(['scope' => 'path:one', 'hard_limit' => 0, 'period_started_at' => now()->startOfMonth()]);
        try {
            app(AiBudgetReservationService::class)->reserve($input);
            self::fail('Exhausted path was admitted');
        } catch (ValidationException $error) {
            self::assertStringContainsString('hard_budget_exhausted:path:one', $error->getMessage());
        }
        $input['path_id'] = 'two';
        self::assertSame($input['id'], app(AiBudgetReservationService::class)->reserve($input)['id']);
    }
}
