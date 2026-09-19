<?php

namespace Tests\Feature\V7;

use App\Models\Stock;
use App\Services\Fundamentals\YahooFundamentalDataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class YahooFundamentalDataProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_cookie_and_crumb_are_reused_for_multiple_requests(): void
    {
        $quote = ['quoteSummary' => ['result' => [[
            'incomeStatementHistoryQuarterly' => ['incomeStatementHistory' => []],
        ]]]];
        Http::fake([
            'https://fc.yahoo.com' => Http::response('', 404, ['Set-Cookie' => 'A1=session-cookie; Path=/']),
            'https://query1.finance.yahoo.com/*' => Http::response('crumb-value', 200),
            'https://query2.finance.yahoo.com/*' => Http::response($quote, 200),
        ]);

        $stock = Stock::query()->create(['symbol' => 'TCS', 'exchange' => 'NSE', 'name' => 'TCS']);
        $provider = new YahooFundamentalDataProvider();

        $provider->fetch($stock, 'quarterly');
        $provider->fetch($stock, 'quarterly');

        Http::assertSentCount(4);
        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), 'query2.finance.yahoo.com')
                && $request['crumb'] === 'crumb-value'
                && $request->hasHeader('User-Agent');
        });
    }

    public function test_invalid_crumb_refreshes_session_once_then_retries(): void
    {
        $quote = ['quoteSummary' => ['result' => [[
            'incomeStatementHistoryQuarterly' => ['incomeStatementHistory' => []],
        ]]]];
        $quoteCalls = 0;
        Http::fake(function (Request $request) use (&$quoteCalls, $quote) {
            if (str_contains($request->url(), 'fc.yahoo.com')) {
                return Http::response('', 404, ['Set-Cookie' => 'A1=session-'.$quoteCalls.'; Path=/']);
            }
            if (str_contains($request->url(), 'getcrumb')) {
                return Http::response($quoteCalls === 0 ? 'crumb-one' : 'crumb-two', 200);
            }
            $quoteCalls++;

            return $quoteCalls === 1
                ? Http::response(['quoteSummary' => ['error' => ['description' => 'Invalid Crumb']]], 200)
                : Http::response($quote, 200);
        });

        $stock = Stock::query()->create(['symbol' => 'INFY', 'exchange' => 'NSE', 'name' => 'Infosys']);
        $provider = new YahooFundamentalDataProvider();

        $provider->fetch($stock, 'quarterly');

        $this->assertSame(2, $quoteCalls);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'query2.finance.yahoo.com') && $request['crumb'] === 'crumb-two');
    }

    public function test_second_authentication_failure_is_explicit_and_does_not_expose_session_values(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'fc.yahoo.com')) {
                return Http::response('', 404, ['Set-Cookie' => 'A1=secret-cookie; Path=/']);
            }
            if (str_contains($request->url(), 'getcrumb')) {
                return Http::response('secret-crumb', 200);
            }

            return Http::response([], 401);
        });

        $stock = Stock::query()->create(['symbol' => 'HDFC', 'exchange' => 'NSE', 'name' => 'HDFC']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('authentication failed after session refresh');

        try {
            (new YahooFundamentalDataProvider())->fetch($stock, 'annual');
        } catch (RuntimeException $error) {
            $this->assertStringNotContainsString('secret-cookie', $error->getMessage());
            $this->assertStringNotContainsString('secret-crumb', $error->getMessage());
            throw $error;
        }
    }
}
