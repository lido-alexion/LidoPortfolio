<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAiRuntimeServiceKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('ai_runtime.shared_secret', '');
        $provided = (string) $request->header('X-StoX-AI-Service-Key', '');

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['success' => false, 'error' => ['code' => 'unauthenticated']], 401);
        }

        return $next($request);
    }
}
