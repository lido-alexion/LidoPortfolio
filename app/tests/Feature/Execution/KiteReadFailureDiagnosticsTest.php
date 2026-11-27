<?php

namespace Tests\Feature\Execution;

use App\Models\BrokerConnection;
use App\Models\User;
use App\Services\Broker\KiteBrokerGateway;
use App\Services\PortfolioLoggerService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class KiteReadFailureDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_trades_schema_failure_emits_only_bounded_categorical_diagnostics(): void
    {
        $user = $this->connectedUser();
        Http::fake(['https://api.kite.trade/trades' => Http::response([
            'status' => 'error', 'data' => ['private_account' => 'secret'], 'message' => 'sensitive body',
        ], 200)]);
        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('event')->once()->with(
            'KiteBrokerGateway', 'broker.read_failed', 'warning', 'Kite read failed',
            ['endpoint' => 'trades', 'failure' => 'schema', 'http_status' => 200],
        );
        $this->app->instance(PortfolioLoggerService::class, $logger);

        $this->assertNull(app(KiteBrokerGateway::class)->trades($user->id));
    }

    public function test_trades_auth_failure_uses_the_same_auth_category_as_other_reads(): void
    {
        $user = $this->connectedUser();
        Http::fake(['https://api.kite.trade/trades' => Http::response(['message' => 'private detail'], 401)]);
        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('event')->once()->with(
            'KiteBrokerGateway', 'broker.read_failed', 'warning', 'Kite read failed',
            ['endpoint' => 'trades', 'failure' => 'auth', 'http_status' => 401],
        );
        $this->app->instance(PortfolioLoggerService::class, $logger);

        $this->assertNull(app(KiteBrokerGateway::class)->trades($user->id));
    }

    public function test_positions_http_failure_fails_closed_instead_of_returning_empty_snapshot(): void
    {
        $user = $this->connectedUser();
        Http::fake(function ($request) {
            return match (true) {
                str_ends_with($request->url(), '/portfolio/holdings') => Http::response(['data' => []]),
                str_ends_with($request->url(), '/portfolio/positions') => Http::response(['message' => 'private detail'], 503),
                str_ends_with($request->url(), '/user/margins/equity') => Http::response(['data' => ['net' => 100]]),
                default => Http::response([], 404),
            };
        });
        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('event')->once()->with(
            'KiteBrokerGateway', 'broker.read_failed', 'warning', 'Kite read failed',
            ['endpoint' => 'portfolio/positions', 'failure' => 'http', 'http_status' => 503],
        );
        $this->app->instance(PortfolioLoggerService::class, $logger);

        $snapshot = app(KiteBrokerGateway::class)->portfolioSnapshot($user->id);

        $this->assertNull($snapshot);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/user/margins/equity'));
    }

    public function test_valid_empty_positions_remain_a_successful_empty_list(): void
    {
        $user = $this->connectedUser();
        Http::fake(function ($request) {
            return match (true) {
                str_ends_with($request->url(), '/portfolio/holdings') => Http::response(['data' => []]),
                str_ends_with($request->url(), '/portfolio/positions') => Http::response(['data' => ['net' => []]]),
                str_ends_with($request->url(), '/user/margins/equity') => Http::response(['data' => ['net' => 100]]),
                default => Http::response([], 404),
            };
        });

        $snapshot = app(KiteBrokerGateway::class)->portfolioSnapshot($user->id);

        $this->assertSame([], $snapshot['holdings']);
        $this->assertSame([], $snapshot['positions']);
        $this->assertSame(100.0, $snapshot['current_cash']);
        $this->assertFalse($snapshot['cash_comparable']);
    }

    public function test_portfolio_snapshot_fails_closed_if_connection_is_replaced_mid_read(): void
    {
        $user = $this->connectedUser();
        config(['broker.kite.api_key' => 'synthetic-key']);
        $connection = BrokerConnection::query()->where('user_id', $user->id)->firstOrFail();
        Http::fake(function ($request) use ($connection) {
            if (str_ends_with($request->url(), '/portfolio/positions')) {
                $connection->forceFill([
                    'access_token' => 'replacement-session',
                    'broker_user_id' => 'replacement-account',
                ])->save();
                return Http::response(['data' => ['net' => []]]);
            }
            if (str_ends_with($request->url(), '/portfolio/holdings')) return Http::response(['data' => []]);
            if (str_ends_with($request->url(), '/user/margins/equity')) return Http::response(['data' => ['net' => 100]]);
            return Http::response([], 404);
        });

        $snapshot = app(KiteBrokerGateway::class)->portfolioSnapshot($user->id);

        $this->assertNull($snapshot);
        $requests = Http::recorded()->map(fn ($pair) => $pair[0]);
        $this->assertCount(3, $requests);
        foreach ($requests as $request) {
            $this->assertTrue($request->hasHeader('Authorization', 'token synthetic-key:synthetic-token'));
        }
    }

    public function test_portfolio_snapshot_reports_the_account_captured_for_its_session(): void
    {
        $user = $this->connectedUser();
        $connection = BrokerConnection::query()->where('user_id', $user->id)->firstOrFail();
        $connection->forceFill(['broker_user_id' => 'synthetic-account'])->save();
        Http::fake(function ($request) {
            return match (true) {
                str_ends_with($request->url(), '/portfolio/holdings') => Http::response(['data' => []]),
                str_ends_with($request->url(), '/portfolio/positions') => Http::response(['data' => ['net' => []]]),
                str_ends_with($request->url(), '/user/margins/equity') => Http::response(['data' => ['net' => 100]]),
                default => Http::response([], 404),
            };
        });

        $snapshot = app(KiteBrokerGateway::class)->portfolioSnapshot($user->id);

        $this->assertSame('synthetic-account', $snapshot['broker_account_id']);
        $this->assertSame(100.0, $snapshot['current_cash']);
    }

    public function test_portfolio_snapshot_fails_closed_without_account_identity(): void
    {
        $user = $this->connectedUser();
        BrokerConnection::query()->where('user_id', $user->id)->update(['broker_user_id' => null]);
        Http::fake();
        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('event')->once()->with(
            'KiteBrokerGateway', 'broker.read_failed', 'warning', 'Kite read failed',
            ['endpoint' => 'portfolio', 'failure' => 'account_identity'],
        );
        $this->app->instance(PortfolioLoggerService::class, $logger);

        $this->assertNull(app(KiteBrokerGateway::class)->portfolioSnapshot($user->id));
        Http::assertNothingSent();
    }

    public function test_positions_require_identity_and_finite_quantity_but_allow_signed_provider_quantity(): void
    {
        $user = $this->connectedUser();
        Http::fake(function ($request) {
            return match (true) {
                str_ends_with($request->url(), '/portfolio/holdings') => Http::response(['data' => []]),
                str_ends_with($request->url(), '/portfolio/positions') => Http::response(['data' => ['net' => [
                    ['tradingsymbol' => 'ABC', 'exchange' => 'NSE', 'quantity' => -2],
                ]]]),
                str_ends_with($request->url(), '/user/margins/equity') => Http::response(['data' => ['net' => 100]]),
                default => Http::response([], 404),
            };
        });

        $snapshot = app(KiteBrokerGateway::class)->portfolioSnapshot($user->id);

        $this->assertSame(-2, $snapshot['positions'][0]['quantity']);
    }

    public function test_position_row_without_identity_or_numeric_quantity_fails_closed(): void
    {
        $user = $this->connectedUser();
        Http::fake(function ($request) {
            return match (true) {
                str_ends_with($request->url(), '/portfolio/holdings') => Http::response(['data' => []]),
                str_ends_with($request->url(), '/portfolio/positions') => Http::response(['data' => ['net' => [[]]]]),
                default => Http::response([], 404),
            };
        });
        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('event')->once()->with(
            'KiteBrokerGateway', 'broker.read_failed', 'warning', 'Kite read failed',
            ['endpoint' => 'portfolio/positions', 'failure' => 'schema', 'http_status' => 200],
        );
        $this->app->instance(PortfolioLoggerService::class, $logger);

        $this->assertNull(app(KiteBrokerGateway::class)->portfolioSnapshot($user->id));
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/user/margins/equity'));
    }

    public function test_position_identity_must_be_a_nonblank_string_without_casting_objects_or_arrays(): void
    {
        foreach ([new \stdClass, ['ABC'], 123, '   '] as $identity) {
            $user = $this->connectedUser();
            Http::fake(function ($request) use ($identity) {
                return match (true) {
                    str_ends_with($request->url(), '/portfolio/holdings') => Http::response(['data' => []]),
                    str_ends_with($request->url(), '/portfolio/positions') => Http::response(['data' => ['net' => [
                        ['tradingsymbol' => $identity, 'exchange' => 'NSE', 'quantity' => -1],
                    ]]]),
                    default => Http::response([], 404),
                };
            });
            $logger = Mockery::mock(PortfolioLoggerService::class);
            $logger->shouldReceive('event')->once()->with(
                'KiteBrokerGateway', 'broker.read_failed', 'warning', 'Kite read failed',
                ['endpoint' => 'portfolio/positions', 'failure' => 'schema', 'http_status' => 200],
            );
            $this->app->instance(PortfolioLoggerService::class, $logger);

            $this->assertNull(app(KiteBrokerGateway::class)->portfolioSnapshot($user->id));
            Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/user/margins/equity'));
        }
    }

    public function test_holdings_reject_blank_identity_and_nonfinite_quantities(): void
    {
        foreach ([
            ['tradingsymbol' => ' ', 'exchange' => 'NSE', 'quantity' => 1],
            ['tradingsymbol' => 'ABC', 'exchange' => 'NSE', 'quantity' => '1e999'],
            ['tradingsymbol' => 'ABC', 'exchange' => 'NSE', 'quantity' => 1, 'average_price' => 'INF'],
        ] as $row) {
            $user = $this->connectedUser();
            Http::fake(['https://api.kite.trade/portfolio/holdings' => Http::response(['data' => [$row]])]);
            $logger = Mockery::mock(PortfolioLoggerService::class);
            $logger->shouldReceive('event')->once()->with(
                'KiteBrokerGateway', 'broker.read_failed', 'warning', 'Kite read failed',
                ['endpoint' => 'portfolio/holdings', 'failure' => 'schema', 'http_status' => 200],
            );
            $this->app->instance(PortfolioLoggerService::class, $logger);

            $this->assertNull(app(KiteBrokerGateway::class)->portfolioSnapshot($user->id));
        }
    }

    public function test_funds_reject_nonfinite_numbers_but_preserve_signed_finite_quantities(): void
    {
        $user = $this->connectedUser();
        Http::fake(['https://api.kite.trade/user/margins/equity' => Http::response([
            'data' => ['available' => ['live_balance' => '1e999']],
        ])]);
        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('event')->once()->with(
            'KiteBrokerGateway', 'broker.read_failed', 'warning', 'Kite read failed',
            ['endpoint' => 'user/margins/equity', 'failure' => 'schema', 'http_status' => 200],
        );
        $this->app->instance(PortfolioLoggerService::class, $logger);

        $this->assertNull(app(KiteBrokerGateway::class)->availableEquityFunds($user->id));
    }

    public function test_positions_invalid_schema_fails_closed_and_logs_only_category(): void
    {
        $user = $this->connectedUser();
        Http::fake(function ($request) {
            return match (true) {
                str_ends_with($request->url(), '/portfolio/holdings') => Http::response(['data' => []]),
                str_ends_with($request->url(), '/portfolio/positions') => Http::response(['data' => ['net' => ['bad' => 'private']]]),
                default => Http::response([], 404),
            };
        });
        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('event')->once()->with(
            'KiteBrokerGateway', 'broker.read_failed', 'warning', 'Kite read failed',
            ['endpoint' => 'portfolio/positions', 'failure' => 'schema', 'http_status' => 200],
        );
        $this->app->instance(PortfolioLoggerService::class, $logger);

        $this->assertNull(app(KiteBrokerGateway::class)->portfolioSnapshot($user->id));
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/user/margins/equity'));
    }

    public function test_holdings_transport_failure_fails_closed_and_logs_only_category(): void
    {
        $user = $this->connectedUser();
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/portfolio/holdings')) {
                throw new ConnectionException('synthetic sensitive transport detail');
            }
            return Http::response(['data' => ['net' => []]]);
        });
        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('event')->once()->with(
            'KiteBrokerGateway', 'broker.read_failed', 'warning', 'Kite read failed',
            ['endpoint' => 'portfolio/holdings', 'failure' => 'transport'],
        );
        $this->app->instance(PortfolioLoggerService::class, $logger);

        $this->assertNull(app(KiteBrokerGateway::class)->portfolioSnapshot($user->id));
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/user/margins/equity'));
    }

    public function test_invalid_provider_token_is_categorized_as_auth_failure(): void
    {
        $user = $this->connectedUser();
        Http::fake(['https://api.kite.trade/portfolio/holdings' => Http::response(['message' => 'private token details'], 403)]);
        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('event')->once()->with(
            'KiteBrokerGateway', 'broker.read_failed', 'warning', 'Kite read failed',
            ['endpoint' => 'portfolio/holdings', 'failure' => 'auth', 'http_status' => 403],
        );
        $this->app->instance(PortfolioLoggerService::class, $logger);

        $this->assertNull(app(KiteBrokerGateway::class)->portfolioSnapshot($user->id));
    }

    public function test_missing_session_is_diagnosed_without_logging_credentials(): void
    {
        $user = User::factory()->create();
        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('event')->once()->with(
            'KiteBrokerGateway', 'broker.read_failed', 'warning', 'Kite read failed',
            ['endpoint' => 'portfolio', 'failure' => 'session'],
        );
        $this->app->instance(PortfolioLoggerService::class, $logger);

        try {
            app(KiteBrokerGateway::class)->portfolioSnapshot($user->id);
            $this->fail('Missing session must continue to fail closed.');
        } catch (\App\Exceptions\DomainException $error) {
            $this->assertSame('BROKER_SESSION_EXPIRED', $error->errorCode());
        }
    }

    public function test_direct_reads_diagnose_missing_session_without_provider_requests(): void
    {
        $user = User::factory()->create();
        Http::fake();
        $logger = Mockery::mock(PortfolioLoggerService::class);
        foreach (['user/margins/equity', 'trades'] as $endpoint) {
            $logger->shouldReceive('event')->once()->with(
                'KiteBrokerGateway', 'broker.read_failed', 'warning', 'Kite read failed',
                ['endpoint' => $endpoint, 'failure' => 'session'],
            );
        }
        $this->app->instance(PortfolioLoggerService::class, $logger);
        foreach (['availableEquityFunds', 'trades'] as $method) {
            try {
                app(KiteBrokerGateway::class)->{$method}($user->id);
                $this->fail('Missing session must continue to fail closed.');
            } catch (\App\Exceptions\DomainException $error) {
                $this->assertSame('BROKER_SESSION_EXPIRED', $error->errorCode());
            }
        }
        Http::assertNothingSent();
    }

    private function connectedUser(): User
    {
        $user = User::factory()->create();
        BrokerConnection::query()->create([
            'user_id' => $user->id, 'provider' => 'kite', 'broker_user_id' => 'synthetic-account',
            'connected_at' => now(), 'expires_at' => null,
        ])->forceFill(['access_token' => 'synthetic-token'])->save();

        return $user;
    }
}
