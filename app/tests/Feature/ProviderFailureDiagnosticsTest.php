<?php

namespace Tests\Feature;

use App\Jobs\CreateOrLinkGitHubIssueJob;
use App\Jobs\TriageLogErrorJob;
use App\Models\ApiFailureIncident;
use App\Models\LogErrorTriage;
use App\Models\Stock;
use App\Services\Operations\ApiFailureReporter;
use App\Services\Operations\GitHubIssueReporter;
use App\Services\Operations\ProviderFailureEvidence;
use App\Services\PortfolioLoggerService;
use App\Services\PriceFetchService;
use App\Support\PriceProviderRequestContext;
use Carbon\Carbon;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class ProviderFailureDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Bus::fake();
        config(['api_failure_reporting.enabled' => false]);
    }

    private function stock(): Stock
    {
        return Stock::query()->create([
            'symbol' => 'RELIANCE', 'name' => 'Reliance', 'exchange' => 'NSE',
            'series' => 'EQ', 'isin' => 'INE002A01018', 'is_active' => true,
        ]);
    }


    public function test_protected_provider_evidence_stays_out_of_enabled_ai_triage_without_disabling_api_reporting(): void
    {
        config([
            'log_error_triage.enabled' => true,
            'log_error_triage.environments' => ['testing'],
            'log_error_triage.build_sha' => 'abcdef0123456',
            'log_error_triage.queue_connection' => 'database',
            'api_failure_reporting.enabled' => true,
            'api_failure_reporting.environments' => ['testing'],
            'api_failure_reporting.queue_connection' => 'database',
            'ai_runtime.enabled' => true,
            'ai_runtime.base_url' => 'http://ai.test',
        ]);
        $protected = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$protected): void {
            if ($event->message === 'Provider HTTP failure evidence') {
                $protected[] = $event;
            }
        });
        Http::fake([
            'https://www.nseindia.com/api/quote-equity' => Http::response([], 403),
        ]);
        $stock = $this->stock();
        $day = Carbon::parse('2026-10-15');
        $response = PriceProviderRequestContext::run($stock, 'nse', 'RELIANCE', $day, $day,
            fn () => Http::get('https://www.nseindia.com/api/quote-equity'));

        $this->assertSame(403, $response->status());
        $incident = ApiFailureIncident::query()->sole();
        $this->assertSame(403, $incident->http_status);
        $this->assertSame('https://nseindia.com/api/quote-equity', $incident->endpoint);
        $this->assertCount(1, $protected);
        $this->assertSame('error', $protected[0]->level);
        $this->assertTrue($protected[0]->context['skip_log_error_triage']);
        $this->assertSame('RELIANCE', $protected[0]->context['symbol']);
        $this->assertSame($incident->trace_id, $protected[0]->context['request_id']);
        $this->assertDatabaseCount('stox_log_error_triages', 0);
        Bus::assertNotDispatched(TriageLogErrorJob::class);
        Bus::assertDispatched(CreateOrLinkGitHubIssueJob::class, fn ($job) => $job->incidentId === $incident->id);
        Bus::assertDispatchedTimes(CreateOrLinkGitHubIssueJob::class, 1);

        // Positive control: the same enabled event path must still admit an
        // ordinary application error, so exclusion cannot pass via disabled triage.
        app(PortfolioLoggerService::class)->api('error', 'Ordinary application failure control');
        $this->assertDatabaseCount('stox_log_error_triages', 1);
        Bus::assertDispatchedTimes(TriageLogErrorJob::class, 1);
        Bus::assertDispatchedTimes(CreateOrLinkGitHubIssueJob::class, 1);
        $this->assertDatabaseCount('portfolio_api_failure_incidents', 1);
        Http::assertSentCount(1);
        $this->assertNull(PriceProviderRequestContext::current());
    }

    public function test_fixed_nse_operations_are_actionable_and_still_request_invariant(): void
    {
        Http::fake(fn () => Http::response('private HTML token=secret user@example.com', 403));
        foreach (['RELIANCE', 'INFY'] as $symbol) {
            Http::get('https://www.nseindia.com/api/quote-equity', ['symbol' => $symbol, 'token' => 'query-secret']);
        }
        Http::get('https://www.nseindia.com/api/holiday-master?type=trading&token=query-secret');

        $rows = ApiFailureIncident::query()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('https://nseindia.com/api/quote-equity', $rows[0]->endpoint);
        $this->assertSame(2, $rows[0]->occurrence_count);
        $this->assertSame('https://nseindia.com/api/holiday-master', $rows[1]->endpoint);
        $this->assertNotSame($rows[0]->fingerprint, $rows[1]->fingerprint);
        $this->assertSame('operation=nse.quote_equity; provider_error=http_forbidden', $rows[0]->safe_message);
        $this->assertStringNotContainsString('query-secret', $rows->toJson());
        $this->assertStringNotContainsString('user@example.com', $rows->toJson());
        $this->assertStringNotContainsString('RELIANCE', $rows->toJson());

        $github = Mockery::mock(GitHubIssueReporter::class);
        $github->shouldReceive('report')->once()->withArgs(function ($marker, $title, $body) use ($rows) {
            return $title === '[Auto][API] NSE quote-equity 403'
                && str_contains($marker, $rows[0]->fingerprint)
                && str_contains($body, 'nse.quote_equity')
                && ! str_contains($body, 'query-secret');
        })->andReturn(['status' => 'linked', 'number' => 1001, 'url' => 'https://github.com/example/issues/1001']);
        (new CreateOrLinkGitHubIssueJob($rows[0]->id))->handle($github);
    }

    public function test_unknown_nse_paths_do_not_gain_a_public_raw_route_or_response_excerpt(): void
    {
        Http::fake(fn () => Http::response(['message' => 'Bearer BODY_SECRET'], 403));
        Http::get('https://www.nseindia.com/api/quote-equity/PrivateName?token=secret');
        $incident = ApiFailureIncident::query()->sole();

        $this->assertSame('https://nseindia.com/api/{resource}/{resource}', $incident->endpoint);
        $this->assertNull($incident->safe_message);
        $this->assertNull($incident->trace_id);
        $this->assertStringNotContainsString('BODY_SECRET', $incident->toJson());
        $this->assertStringNotContainsString('PrivateName', $incident->toJson());
    }

    public function test_yahoo_error_enums_do_not_fragment_instruments_or_disclose_bodies(): void
    {
        Http::fake([
            '*RELIANCE.NS*' => Http::response(['chart' => ['error' => ['code' => 'Not Found', 'description' => 'token=BODY_SECRET user@example.com']]], 404),
            '*INFY.NS*' => Http::response(['chart' => ['error' => ['code' => 'Not Found', 'description' => 'private arbitrary text']]], 404),
            '*OTHER.NS*' => Http::response(['chart' => ['error' => ['code' => 'BODY_SECRET', 'description' => 'private']]], 400),
        ]);
        Http::get('https://query1.finance.yahoo.com/v8/finance/chart/RELIANCE.NS?token=QUERY_SECRET');
        Http::get('https://query2.finance.yahoo.com/v8/finance/chart/INFY.NS');
        Http::get('https://query1.finance.yahoo.com/v8/finance/chart/OTHER.NS');

        $rows = ApiFailureIncident::query()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(2, $rows[0]->occurrence_count);
        $this->assertSame('operation=yahoo.chart; provider_error=chart_not_found', $rows[0]->safe_message);
        $this->assertSame('operation=yahoo.chart; provider_error=chart_unclassified', $rows[1]->safe_message);
        foreach (['BODY_SECRET', 'QUERY_SECRET', 'user@example.com', 'RELIANCE', 'INFY', 'OTHER'] as $private) {
            $this->assertStringNotContainsString($private, $rows->toJson());
        }
    }

    public function test_canonical_price_failures_link_private_reproduction_evidence_without_changing_fallback(): void
    {
        $stock = $this->stock();
        $logs = [];
        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('provider')->times(4)->andReturnUsing(function ($level, $message, $context) use (&$logs) {
            $this->assertSame('error', $level);
            $this->assertSame('Provider HTTP failure evidence', $message);
            $logs[] = $context;
        });
        $this->app->instance(PortfolioLoggerService::class, $logger);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'RELIANCE.NS')) {
                return Http::response(['chart' => ['error' => ['code' => 'Not Found', 'description' => 'private body']]], 404);
            }
            return Http::response(['chart' => ['result' => [[
                'timestamp' => [Carbon::parse('2026-10-16 12:00:00', 'Asia/Kolkata')->timestamp],
                'indicators' => ['quote' => [[
                    'open' => [100], 'high' => [105], 'low' => [95], 'close' => [102], 'volume' => [1000],
                ]]],
            ]]]], 200);
        });
        $from = Carbon::parse('2026-10-15', 'Asia/Kolkata');
        $to = Carbon::parse('2026-10-23', 'Asia/Kolkata');
        $service = app(PriceFetchService::class);
        $first = $service->fetchFromProvider('yahoo', 'RELIANCE', $from, $to, $stock);
        $second = $service->fetchFromProvider('yahoo', 'RELIANCE', $from, $to, $stock);
        Http::assertSentCount(6);
        $this->assertCount(1, $first['rows']);
        $this->assertCount(1, $second['rows']);
        $this->assertSame([], $first['errors']);
        $this->assertNull(PriceProviderRequestContext::current());
        $this->assertDatabaseCount('portfolio_stock_prices', 0);

        $incident = ApiFailureIncident::query()->sole();
        $this->assertSame(4, $incident->occurrence_count);
        $this->assertSame('https://yahoo.com/v8/finance/chart/{resource}', $incident->endpoint);
        $this->assertSame($logs[2]['request_id'], $logs[3]['request_id']);
        $this->assertSame($logs[3]['request_id'], $incident->trace_id);
        $this->assertNotSame($logs[0]['request_id'], $logs[2]['request_id']);
        foreach ($logs as $log) {
            $this->assertSame('RELIANCE', $log['symbol']);
            $this->assertSame('RELIANCE.NS', $log['provider_symbol']);
            $this->assertSame('2026-10-15', $log['from_date']);
            $this->assertSame('2026-10-23', $log['to_date']);
            $this->assertSame('Asia/Kolkata', $log['from_timezone']);
            $this->assertSame('chart_not_found', $log['provider_error']);
            $this->assertMatchesRegularExpression('/\A[0-9a-f-]{36}\z/', $log['request_id']);
        }
        foreach (['RELIANCE', '2026-10-15', 'private body'] as $private) {
            $this->assertStringNotContainsString($private, $incident->endpoint.(string) $incident->safe_message);
        }
    }

    public function test_scope_and_capture_are_fail_open_and_never_leak_into_later_requests(): void
    {
        $stock = $this->stock();
        $from = Carbon::parse('2026-10-15');
        $to = Carbon::parse('2026-10-23');
        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('provider')->once()->andThrow(new \RuntimeException('sink unavailable'));
        $this->app->instance(PortfolioLoggerService::class, $logger);
        Http::fake(fn () => Http::response([], 403));

        $response = PriceProviderRequestContext::run($stock, 'nse', 'RELIANCE', $from, $to,
            fn () => Http::get('https://www.nseindia.com/api/quote-equity'));
        $this->assertSame(403, $response->status());
        $this->assertNotNull(ApiFailureIncident::query()->sole()->trace_id);
        $this->assertNull(PriceProviderRequestContext::current());
        Http::get('https://www.nseindia.com/api/holiday-master');
        $this->assertNull(ApiFailureIncident::query()->orderByDesc('id')->first()->trace_id);

        try {
            PriceProviderRequestContext::run($stock, 'nse', 'RELIANCE', $from, $to,
                fn () => throw new \RuntimeException('original provider exception'));
            $this->fail('Expected the original provider exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('original provider exception', $e->getMessage());
        }
        $this->assertNull(PriceProviderRequestContext::current());

        Str::createUuidsUsing(fn () => throw new \RuntimeException('correlation unavailable'));
        try {
            $this->assertSame('original result', PriceProviderRequestContext::run(
                $stock, 'nse', 'RELIANCE', $from, $to, fn () => 'original result',
            ));
        } finally {
            Str::createUuidsNormally();
        }
    }




    public function test_private_evidence_survives_a_reporter_adapter_failure(): void
    {
        $stock = $this->stock();
        $day = Carbon::parse('2026-10-15');
        $reporter = Mockery::mock(ApiFailureReporter::class);
        $reporter->shouldReceive('observeExternalResponse')->once()->andThrow(new \RuntimeException('capture unavailable'));
        $this->app->instance(ApiFailureReporter::class, $reporter);
        $logged = null;
        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('provider')->once()->andReturnUsing(function ($level, $message, $context) use (&$logged) {
            $logged = $context;
        });
        $this->app->instance(PortfolioLoggerService::class, $logger);
        Http::fake(fn () => Http::response([], 403));

        $response = PriceProviderRequestContext::run($stock, 'nse', 'RELIANCE', $day, $day,
            fn () => Http::get('https://www.nseindia.com/api/quote-equity'));
        $this->assertSame(403, $response->status());
        $this->assertSame('RELIANCE', $logged['symbol']);
        $this->assertSame('nse.quote_equity', $logged['operation']);
        $this->assertNotEmpty($logged['request_id']);
        $this->assertNull(PriceProviderRequestContext::current());
    }

    public function test_expected_status_policy_does_not_create_private_error_noise(): void
    {
        $stock = $this->stock();
        $day = Carbon::parse('2026-10-15');
        config(['api_failure_reporting.expected_endpoint_statuses' => ['https://nseindia.com/api/quote-equity' => [403]]]);
        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldNotReceive('provider');
        $this->app->instance(PortfolioLoggerService::class, $logger);
        Http::fake(fn () => Http::response([], 403));

        $response = PriceProviderRequestContext::run($stock, 'nse', 'RELIANCE', $day, $day,
            fn () => Http::get('https://www.nseindia.com/api/quote-equity'));
        $this->assertSame(403, $response->status());
        $this->assertDatabaseCount('portfolio_api_failure_incidents', 0);
        $this->assertNull(PriceProviderRequestContext::current());
    }

    public function test_canonical_transport_failure_keeps_safe_correlation_and_original_exception(): void
    {
        $stock = $this->stock();
        $day = Carbon::parse('2026-10-15');
        $logged = null;
        $logger = Mockery::mock(PortfolioLoggerService::class);
        $logger->shouldReceive('provider')->once()->andReturnUsing(function ($level, $message, $context) use (&$logged) {
            $logged = $context;
        });
        $this->app->instance(PortfolioLoggerService::class, $logger);
        Http::fake(['*' => Http::failedConnection('token=TRANSPORT_SECRET')]);

        try {
            PriceProviderRequestContext::run($stock, 'yahoo', 'RELIANCE.NS', $day, $day,
                fn () => Http::get('https://query1.finance.yahoo.com/v8/finance/chart/RELIANCE.NS'));
            $this->fail('Expected the original connection failure');
        } catch (\Illuminate\Http\Client\ConnectionException) {
            // The observer must not absorb the originating transport failure.
        }

        $incident = ApiFailureIncident::query()->sole();
        $this->assertSame('ConnectionException', $incident->failure_class);
        $this->assertNull($incident->http_status);
        $this->assertSame('operation=yahoo.chart; provider_error=transport_failure', $incident->safe_message);
        $this->assertSame($logged['request_id'], $incident->trace_id);
        $this->assertStringNotContainsString('TRANSPORT_SECRET', $incident->toJson());
        $this->assertNull(PriceProviderRequestContext::current());
    }

    public function test_nested_and_noncanonical_scopes_restore_the_outer_context(): void
    {
        $stock = $this->stock();
        $day = Carbon::parse('2026-10-15');
        PriceProviderRequestContext::run($stock, 'yahoo', 'RELIANCE.NS', $day, $day, function () use ($stock, $day) {
            $outer = PriceProviderRequestContext::current();
            PriceProviderRequestContext::run($stock, 'yahoo', 'RELIANCE.BO', $day, $day, function () use ($outer) {
                $this->assertSame('RELIANCE.BO', PriceProviderRequestContext::current()['provider_symbol']);
                $this->assertNotSame($outer['request_id'], PriceProviderRequestContext::current()['request_id']);
            });
            $this->assertSame($outer, PriceProviderRequestContext::current());
            PriceProviderRequestContext::run(null, 'yahoo', 'RAW_LOOKUP', $day, $day, function () {
                $this->assertNull(PriceProviderRequestContext::current());
            });
            $this->assertSame($outer, PriceProviderRequestContext::current());
        });
        $this->assertNull(PriceProviderRequestContext::current());
    }

    public function test_error_body_read_is_bounded_and_does_not_change_provider_stream(): void
    {
        $operation = ProviderFailureEvidence::operation('yahoo.com', '/v8/finance/chart/RELIANCE.NS');
        $body = '{"chart":{"error":{"code":"Not Found","description":"private"}}}';
        $stream = Utils::streamFor($body);
        $stream->seek(7);
        $response = new Response(new PsrResponse(404, [], $stream));
        $this->assertSame('chart_not_found', ProviderFailureEvidence::category($operation, 404, $response));
        $this->assertSame(7, $stream->tell());
        $stream->rewind();
        $this->assertSame($body, $stream->getContents());

        foreach ([
            new PsrResponse(404, [], str_repeat('X', 8193)),
            new PsrResponse(404, [], new NoSeekStream(Utils::streamFor($body))),
            new PsrResponse(404, [], '{"chart":"PRIVATE"}'),
            new PsrResponse(404, [], 'not JSON private'),
        ] as $raw) {
            $this->assertSame('chart_unclassified', ProviderFailureEvidence::category($operation, 404, new Response($raw)));
        }
    }
}
