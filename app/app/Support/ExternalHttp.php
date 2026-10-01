<?php

namespace App\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use App\Services\Operations\ApiFailureReporter;

class ExternalHttp
{
    public static function client(): PendingRequest
    {
        $options = ['timeout' => 25];

        $caFile = config('portfolio.ca_bundle');
        if ($caFile && is_readable($caFile)) {
            $options['verify'] = $caFile;
        } elseif (config('portfolio.ssl_verify') === false) {
            $options['verify'] = false;
        }

        $options['on_stats'] = function ($stats): void {
            $request = $stats->getRequest();
            if ($request->getHeaderLine('X-StoX-Skip-Api-Failure-Reporting') === '1') return;
            $response = $stats->hasResponse() ? $stats->getResponse() : null;
            try {
                app(ApiFailureReporter::class)->observeExternalResponse(
                    'external-http',
                    $request->getMethod(),
                    (string) $request->getUri(),
                    $response?->getStatusCode(),
                    ['failure_class' => $stats->getHandlerStats()['errno'] ?? null],
                );
            } catch (\Throwable) {
                // Observation is strictly fail-open.
            }
        };

        return Http::withOptions($options);
    }
}
