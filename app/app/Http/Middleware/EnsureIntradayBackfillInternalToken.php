<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIntradayBackfillInternalToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = trim((string) config('intraday_ml_platform.internal_token'));
        if ($expected === '') {
            abort(503, 'Intraday backfill internal API is not configured.');
        }

        $provided = trim((string) $request->bearerToken());
        if ($provided === '') {
            $provided = trim((string) $request->header('X-Intraday-Backfill-Token'));
        }

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            abort(401, 'Invalid intraday backfill credentials.');
        }

        return $next($request);
    }
}
