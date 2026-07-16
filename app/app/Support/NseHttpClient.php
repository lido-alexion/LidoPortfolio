<?php

namespace App\Support;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;

class NseHttpClient
{
    /**
     * NSE APIs often return HTTP 403 without a browser session cookie.
     * Warm the cookie jar with a homepage request, then reuse the same client.
     */
    public static function create(): PendingRequest
    {
        $cookieJar = new CookieJar();

        $client = ExternalHttp::client()
            ->withOptions(['cookies' => $cookieJar])
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'application/json,text/plain,*/*',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Referer' => 'https://www.nseindia.com/',
            ])
            ->timeout(20)
            ->retry(1, 500);

        try {
            $client->get('https://www.nseindia.com/');
            $client->get('https://www.nseindia.com/api/marketStatus');
        } catch (\Throwable) {
            // Continue — quote/historical call may still succeed.
        }

        return $client;
    }
}
