<?php

namespace Tests\Unit;

use App\Support\NseHttpClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NseHttpClientTest extends TestCase
{
    public function test_create_uses_cookie_jar_without_guzzle_type_error(): void
    {
        Http::fake([
            'www.nseindia.com/*' => Http::response(['data' => []], 200),
        ]);

        $client = NseHttpClient::create();

        $response = $client->get('https://www.nseindia.com/api/historical/cm/equity', [
            'symbol' => 'RELIANCE',
            'series' => '["EQ"]',
            'from' => '01-01-2024',
            'to' => '31-01-2024',
        ]);

        $this->assertTrue($response->successful());
        Http::assertSentCount(3);
    }
}
