<?php

namespace Tests\Feature\V8;

use App\Http\Controllers\Api\AuthController;
use App\Models\Stock;
use App\Models\User;
use App\Services\StrategyConfigurationService;
use App\Telemetry\LidoTelemetry;
use App\Telemetry\LidoTelemetryCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class LidoTelemetryBusinessEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_success_records_auth_business_telemetry(): void
    {
        $telemetry = $this->createMock(LidoTelemetry::class);
        $telemetry->expects($this->once())
            ->method('recordBusinessEvent')
            ->with(
                LidoTelemetryCatalog::BUSINESS_AUTH_LOGIN_SUCCEEDED,
                $this->callback(fn (array $attrs): bool => ($attrs['role'] ?? '') === 'investor'),
            );
        $this->instance(LidoTelemetry::class, $telemetry);

        $user = User::factory()->create();
        $request = Request::create('/api/auth/login', 'POST', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => false,
        ]);
        $request->headers->set('Accept', 'application/json');
        $session = app('session.store');
        $session->start();
        $request->setLaravelSession($session);

        $response = app(AuthController::class)->login($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_login_failure_records_auth_business_telemetry(): void
    {
        config(['lido_telemetry.enabled' => false]);

        $telemetry = $this->spy(LidoTelemetry::class);

        $user = User::factory()->create();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);

        $telemetry->shouldHaveReceived('recordBusinessEvent')
            ->once()
            ->with(LidoTelemetryCatalog::BUSINESS_AUTH_LOGIN_FAILED, Mockery::on(function (array $attrs): bool {
                return ($attrs['reason'] ?? '') === 'invalid_credentials';
            }));
    }

    public function test_fundamentals_show_records_business_telemetry(): void
    {
        config(['lido_telemetry.enabled' => true, 'lido_telemetry.otlp_traces_endpoint' => 'http://collector.test/v1/traces']);

        $this->mock(LidoTelemetry::class, function ($mock): void {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldReceive('pseudonymousUserId')->andReturn('pseudo');
            $mock->shouldReceive('recordHttpRequest')->andReturnNull();
            $mock->shouldReceive('recordBusinessEvent')
                ->once()
                ->with(LidoTelemetryCatalog::BUSINESS_FUNDAMENTALS_VIEW, Mockery::on(function (array $attrs): bool {
                    return isset($attrs['stock_id']) && $attrs['include_insights'] === false;
                }));
        });

        $user = User::factory()->create();
        $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create(['symbol' => 'TEL', 'exchange' => 'NSE', 'name' => 'Telemetry']);

        $this->actingAs($user)->withProfileHeader($user)
            ->getJson("/api/v1/stocks/{$stock->id}/fundamentals")
            ->assertOk();
    }

    public function test_fundamentals_ai_insights_records_dedicated_telemetry(): void
    {
        config(['lido_telemetry.enabled' => true, 'fundamentals_ai.enabled' => false]);

        $this->mock(LidoTelemetry::class, function ($mock): void {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldReceive('pseudonymousUserId')->andReturn('pseudo');
            $mock->shouldReceive('recordHttpRequest')->andReturnNull();
            $mock->shouldReceive('recordBusinessEvent')
                ->once()
                ->with(LidoTelemetryCatalog::BUSINESS_FUNDAMENTALS_VIEW, Mockery::type('array'));
            $mock->shouldReceive('recordBusinessEvent')
                ->once()
                ->with(LidoTelemetryCatalog::BUSINESS_FUNDAMENTALS_AI_INSIGHTS, Mockery::on(function (array $attrs): bool {
                    return isset($attrs['stock_id']) && $attrs['surface'] === 'fundamentals_api';
                }));
        });

        $user = User::factory()->create();
        $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create(['symbol' => 'FAI', 'exchange' => 'NSE', 'name' => 'Fund AI']);

        $this->actingAs($user)->withProfileHeader($user)
            ->getJson("/api/v1/stocks/{$stock->id}/fundamentals?include_ai_insights=1")
            ->assertOk();
    }

    public function test_ml_insights_records_business_telemetry(): void
    {
        config(['lido_telemetry.enabled' => true]);

        $this->mock(LidoTelemetry::class, function ($mock): void {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldReceive('pseudonymousUserId')->andReturn('pseudo');
            $mock->shouldReceive('recordHttpRequest')->andReturnNull();
            $mock->shouldReceive('recordBusinessEvent')
                ->once()
                ->with(LidoTelemetryCatalog::BUSINESS_ML_INSIGHTS_VIEW, Mockery::type('array'));
        });

        $user = User::factory()->create();
        $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create(['symbol' => 'MLT', 'exchange' => 'NSE', 'name' => 'ML Tel']);

        $this->actingAs($user)->withProfileHeader($user)
            ->getJson("/api/v1/stocks/{$stock->id}/ml-insights")
            ->assertOk();
    }

    public function test_screener_run_records_versioned_business_telemetry(): void
    {
        config(['lido_telemetry.enabled' => true]);

        $this->mock(LidoTelemetry::class, function ($mock): void {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldReceive('pseudonymousUserId')->andReturn('pseudo');
            $mock->shouldReceive('recordHttpRequest')->andReturnNull();
            $mock->shouldReceive('recordBusinessEvent')
                ->once()
                ->with(LidoTelemetryCatalog::BUSINESS_SCREENER_RUN, Mockery::on(function (array $attrs): bool {
                    return isset($attrs['screener_id'], $attrs['screener_version_id'], $attrs['run_id'])
                        && $attrs['scope'] === 'holdings';
                }));
        });

        $user = User::factory()->create();
        $this->defaultPortfolioFor($user);

        $created = $this->actingAs($user)->postJson('/api/screeners', [
            'name' => 'Telemetry run',
            'scope' => 'holdings',
            'definition_json' => [
                'root' => [
                    'type' => 'condition',
                    'left' => ['indicator' => 'close'],
                    'operator' => 'gt',
                    'right' => ['type' => 'constant', 'value' => 0],
                ],
            ],
        ])->assertCreated()->json('data');

        $this->actingAs($user)->postJson('/api/screeners/'.$created['id'].'/run')->assertOk();
    }

    public function test_recommendation_preview_records_business_telemetry(): void
    {
        config(['lido_telemetry.enabled' => true]);

        $this->mock(LidoTelemetry::class, function ($mock): void {
            $mock->shouldReceive('enabled')->andReturn(true);
            $mock->shouldReceive('pseudonymousUserId')->andReturn('pseudo');
            $mock->shouldReceive('recordHttpRequest')->andReturnNull();
            $mock->shouldReceive('recordBusinessEvent')
                ->once()
                ->with(LidoTelemetryCatalog::BUSINESS_RECOMMENDATION_VIEW, Mockery::on(function (array $attrs): bool {
                    return isset($attrs['stock_id']) && $attrs['surface'] === 'recommendation_preview';
                }));
        });

        $user = User::factory()->create();
        $profile = $this->defaultPortfolioFor($user);
        $stock = Stock::query()->create(['symbol' => 'REC', 'exchange' => 'NSE', 'name' => 'Rec Tel', 'is_active' => true]);
        $strategy = app(StrategyConfigurationService::class)->ensureActive($profile)->strategy;

        $this->actingAs($user)->withProfileHeader($user)
            ->getJson("/api/v1/analytics/stocks/{$stock->id}/recommendation-preview?strategy_id={$strategy->id}")
            ->assertOk();
    }
}
