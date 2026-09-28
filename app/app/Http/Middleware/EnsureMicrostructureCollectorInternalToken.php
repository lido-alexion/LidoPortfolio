<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMicrostructureCollectorInternalToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = trim((string) config('microstructure_collector.internal_token'));
        if ($expected === '') {
            abort(503, 'Microstructure collector internal API is not configured.');
        }

        $provided = trim((string) $request->bearerToken());
        if ($provided === '') {
            $provided = trim((string) $request->header('X-Microstructure-Collector-Token'));
        }

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            abort(401, 'Invalid collector credentials.');
        }

        return $next($request);
    }
}
