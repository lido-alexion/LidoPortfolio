<?php

namespace App\Support;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;

class NseChartingHttpClient
{
    public static function create(): PendingRequest
    {
        $cookieJar = new CookieJar();

        $client = ExternalHttp::client()
            ->withOptions(['cookies' => $cookieJar])
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept' => 'application/json,text/plain,*/*',
                'Accept-Language' => 'en-US,en;q=0.9',
                'Referer' => 'https://charting.nseindia.com/',
            ])
            ->timeout(25)
            ->retry(1, 500);

        // The cookie jar is shared, while the optional warm-up opt-out is not.
        $warmup = (clone $client)->withHeaders(['X-StoX-Skip-Api-Failure-Reporting' => '1']);
        try {
            $warmup->get('https://charting.nseindia.com/');
        } catch (\Throwable) {
            // Continue — data call may still succeed.
        }

        return $client;
    }
}
