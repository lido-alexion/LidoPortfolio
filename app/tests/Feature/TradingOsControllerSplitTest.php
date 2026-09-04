<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\TradingOs\DataController;
use App\Http\Controllers\Api\V1\TradingOs\DiscoveryController;
use App\Http\Controllers\Api\V1\TradingOs\EvaluationController;
use App\Http\Controllers\Api\V1\TradingOs\ExecutionController;
use App\Http\Controllers\Api\V1\TradingOs\NotificationController;
use App\Http\Controllers\Api\V1\TradingOs\PipelineController;
use App\Http\Controllers\Api\V1\TradingOs\RecommendationController;
use App\Http\Controllers\Api\V1\TradingOs\ReviewController;
use App\Models\User;
use App\Models\EvaluationRun;
use App\Support\TradingOsConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TradingOsControllerSplitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            TradingOsConfig::KEY_ENABLED => true,
            TradingOsConfig::KEY_NOTIFICATION.'.notify_on_generate' => false,
        ]);
    }

    public function test_former_tos_routes_still_resolve_to_split_controllers(): void
    {
        $expected = [
            ['GET', '/api/v1/securities', DataController::class, 'securities'],
            ['GET', '/api/v1/securities/1', DataController::class, 'securityShow'],
            ['GET', '/api/v1/price-bars', DataController::class, 'priceBars'],
            ['GET', '/api/v1/dataset/status', DataController::class, 'datasetStatus'],
            ['POST', '/api/v1/imports', DataController::class, 'importsStore'],
            ['GET', '/api/v1/imports/abc', DataController::class, 'importsShow'],
            ['POST', '/api/v1/discovery/runs', DiscoveryController::class, 'discoveryRunsStore'],
            ['GET', '/api/v1/candidates', DiscoveryController::class, 'candidates'],
            ['POST', '/api/v1/evaluation/runs', EvaluationController::class, 'evaluationRunsStore'],
            ['GET', '/api/v1/evaluation/runs', EvaluationController::class, 'evaluationRunsIndex'],
            ['GET', '/api/v1/evaluations', EvaluationController::class, 'evaluations'],
            ['POST', '/api/v1/recommendations/generate', RecommendationController::class, 'recommendationsGenerate'],
            ['GET', '/api/v1/recommendations', RecommendationController::class, 'recommendationsIndex'],
            ['GET', '/api/v1/recommendations/pending-execution', RecommendationController::class, 'recommendationsPendingExecution'],
            ['GET', '/api/v1/recommendations/9', RecommendationController::class, 'recommendationsShow'],
            ['POST', '/api/v1/recommendations/9/review', RecommendationController::class, 'recommendationsReview'],
            ['POST', '/api/v1/recommendations/9/reopen', RecommendationController::class, 'recommendationsReopen'],
            ['POST', '/api/v1/recommendations/9/cancel-execution', RecommendationController::class, 'recommendationsCancelExecution'],
            ['POST', '/api/v1/recommendations/9/expire', RecommendationController::class, 'recommendationsExpire'],
            ['GET', '/api/v1/recommendations/9/reviews', RecommendationController::class, 'recommendationsReviewHistory'],
            ['GET', '/api/v1/notifications', NotificationController::class, 'notificationsIndex'],
            ['POST', '/api/v1/notifications/3/retry', NotificationController::class, 'notificationsRetry'],
            ['POST', '/api/v1/orders', ExecutionController::class, 'ordersStore'],
            ['GET', '/api/v1/orders', ExecutionController::class, 'ordersIndex'],
            ['POST', '/api/v1/orders/4/execute', ExecutionController::class, 'ordersExecute'],
            ['POST', '/api/v1/orders/4/cancel', ExecutionController::class, 'ordersCancel'],
            ['GET', '/api/v1/transactions', ExecutionController::class, 'transactionsIndex'],
            ['GET', '/api/v1/positions', ExecutionController::class, 'positionsIndex'],
            ['GET', '/api/v1/execution/mode', ExecutionController::class, 'executionModeShow'],
            ['PUT', '/api/v1/execution/mode', ExecutionController::class, 'executionModeUpdate'],
            ['POST', '/api/v1/execution/submit-selected', ExecutionController::class, 'submitSelected'],
            ['POST', '/api/v1/orders/4/reconcile', ExecutionController::class, 'ordersReconcile'],
            ['GET', '/api/v1/protections', \App\Http\Controllers\Api\V1\TradingOs\ProtectionController::class, 'index'],
            ['POST', '/api/v1/protections', \App\Http\Controllers\Api\V1\TradingOs\ProtectionController::class, 'store'],
            ['GET', '/api/v1/protections/8', \App\Http\Controllers\Api\V1\TradingOs\ProtectionController::class, 'show'],
            ['POST', '/api/v1/protections/8/cancel', \App\Http\Controllers\Api\V1\TradingOs\ProtectionController::class, 'cancel'],
            ['POST', '/api/v1/protections/8/reconcile', \App\Http\Controllers\Api\V1\TradingOs\ProtectionController::class, 'reconcile'],
            ['GET', '/api/v1/totp', \App\Http\Controllers\Api\V1\TradingOs\TotpController::class, 'status'],
            ['POST', '/api/v1/totp/begin', \App\Http\Controllers\Api\V1\TradingOs\TotpController::class, 'begin'],
            ['POST', '/api/v1/totp/confirm', \App\Http\Controllers\Api\V1\TradingOs\TotpController::class, 'confirm'],
            ['POST', '/api/v1/totp/verify', \App\Http\Controllers\Api\V1\TradingOs\TotpController::class, 'verify'],
            ['POST', '/api/v1/totp/recover', \App\Http\Controllers\Api\V1\TradingOs\TotpController::class, 'recover'],
            ['POST', '/api/v1/totp/disable', \App\Http\Controllers\Api\V1\TradingOs\TotpController::class, 'disable'],
            ['GET', '/api/v1/broker/status', \App\Http\Controllers\Api\V1\TradingOs\BrokerController::class, 'status'],
            ['GET', '/api/v1/broker/kite/login-url', \App\Http\Controllers\Api\V1\TradingOs\BrokerController::class, 'kiteLoginUrl'],
            ['GET', '/api/v1/broker/kite/callback', \App\Http\Controllers\Api\V1\TradingOs\BrokerController::class, 'kiteCallback'],
            ['POST', '/api/v1/broker/kite/session', \App\Http\Controllers\Api\V1\TradingOs\BrokerController::class, 'kiteSession'],
            ['POST', '/api/v1/broker/kite/disconnect', \App\Http\Controllers\Api\V1\TradingOs\BrokerController::class, 'disconnect'],
            ['POST', '/api/v1/reviews/generate', ReviewController::class, 'reviewsGenerate'],
            ['GET', '/api/v1/reviews', ReviewController::class, 'reviewsIndex'],
            ['GET', '/api/v1/reviews/2', ReviewController::class, 'reviewsShow'],
            ['GET', '/api/v1/review/dashboard', ReviewController::class, 'reviewDashboard'],
            ['GET', '/api/v1/review/outcomes', ReviewController::class, 'reviewOutcomes'],
            ['POST', '/api/v1/pipeline/run', PipelineController::class, 'pipelineRun'],
            ['PUT', '/api/v1/admin/users/1/automated-execution-entitlement', \App\Http\Controllers\Api\V1\TradingOs\AdminExecutionEntitlementController::class, 'update'],
        ];

        foreach ($expected as [$method, $uri, $controller, $action]) {
            $route = Route::getRoutes()->match(Request::create($uri, $method));
            $this->assertSame($controller, $route->getControllerClass(), $method.' '.$uri);
            $this->assertSame($action, $route->getActionMethod(), $method.' '.$uri);
            if ($uri === '/api/v1/broker/kite/callback') {
                $this->assertNotContains('auth:sanctum', $route->gatherMiddleware(), $method.' '.$uri);
                $this->assertNotContains('active.portfolio', $route->gatherMiddleware(), $method.' '.$uri);
            } else {
                $this->assertContains('auth:sanctum', $route->gatherMiddleware(), $method.' '.$uri);
                $this->assertContains('active.portfolio', $route->gatherMiddleware(), $method.' '.$uri);
            }
        }
    }

    public function test_guest_cannot_read_dataset_status(): void
    {
        $this->getJson('/api/v1/dataset/status')->assertUnauthorized();
    }

    public function test_dataset_status_success_envelope_is_unchanged(): void
    {
        [$user, $profile] = $this->actingPortfolioUser();

        $response = $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->getJson('/api/v1/dataset/status');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'published',
                    'dataset_version',
                    'securities_active',
                    'price_bars',
                    'latest_price_date',
                    'daily_sync',
                ],
                'meta',
            ]);
        $this->assertIsBool($response->json('data.published'));
        $this->assertIsString($response->json('data.dataset_version'));
    }

    public function test_price_bars_require_security_id(): void
    {
        [$user, $profile] = $this->actingPortfolioUser();

        $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->getJson('/api/v1/price-bars')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonPath('error.message', 'security_id is required.');
    }

    public function test_missing_recommendation_returns_not_found_envelope(): void
    {
        [$user, $profile] = $this->actingPortfolioUser();

        $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->getJson('/api/v1/recommendations/999999')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'NOT_FOUND')
            ->assertJsonPath('error.message', 'Recommendation not found.');
    }

    public function test_review_of_missing_recommendation_returns_not_found_before_validation(): void
    {
        [$user, $profile] = $this->actingPortfolioUser();

        $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->postJson('/api/v1/recommendations/999999/review', [])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_order_create_validation_error_shape_is_unchanged(): void
    {
        [$user, $profile] = $this->actingPortfolioUser();

        $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->postJson('/api/v1/orders', [])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors']);
    }

    public function test_empty_list_success_envelopes_are_unchanged(): void
    {
        [$user, $profile] = $this->actingPortfolioUser();
        $this->actingAs($user)->withProfileHeader($user, $profile);

        $this->getJson('/api/v1/candidates')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertExactJson([
                'success' => true,
                'data' => [],
                'meta' => [],
            ]);

        foreach ([
            '/api/v1/recommendations' => 100,
            '/api/v1/notifications' => 50,
            '/api/v1/orders' => 50,
        ] as $uri => $defaultPageSize) {
            $this->getJson($uri)
                ->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('data', [])
                ->assertJsonPath('meta.page', 1)
                ->assertJsonPath('meta.pageSize', $defaultPageSize)
                ->assertJsonPath('meta.total', 0)
                ->assertJsonPath('meta.lastPage', 1);
        }
    }

    public function test_evaluation_without_discovery_returns_precondition_envelope(): void
    {
        [$user, $profile] = $this->actingPortfolioUser();

        $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->postJson('/api/v1/evaluation/runs')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'EVALUATION_PRECONDITION')
            ->assertJsonPath('error.message', 'No completed discovery run available for evaluation.');
    }

    public function test_evaluation_run_history_is_bounded_and_portfolio_scoped(): void
    {
        [$user, $profile] = $this->actingPortfolioUser();

        EvaluationRun::query()->create([
            'profile_id' => $profile->id,
            'status' => 'failed',
            'started_at' => now()->subMinutes(2),
            'error_message' => 'Example failure',
        ]);
        $newer = EvaluationRun::query()->create([
            'profile_id' => $profile->id,
            'status' => 'completed',
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
            'stats_json' => ['evaluated' => 4],
        ]);

        $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->getJson('/api/v1/evaluation/runs?limit=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.0.status', 'completed')
            ->assertJsonPath('data.0.stats.evaluated', 4)
            ->assertJsonPath('data.0.result_count', 0);
    }

    public function test_pipeline_run_without_fresh_dataset_returns_dataset_not_fresh(): void
    {
        [$user, $profile] = $this->actingPortfolioUser();

        $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->postJson('/api/v1/pipeline/run')
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'DATASET_NOT_FRESH');
    }

    public function test_review_dashboard_success_envelope(): void
    {
        [$user, $profile] = $this->actingPortfolioUser();

        $this->actingAs($user)
            ->withProfileHeader($user, $profile)
            ->getJson('/api/v1/review/dashboard')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data']);
    }

    /**
     * @return array{0: User, 1: \App\Models\PortfolioProfile}
     */
    protected function actingPortfolioUser(): array
    {
        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);

        return [$user, $profile];
    }
}
