<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePersonalApiTokenScope
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $token = $request->user()?->currentAccessToken();
        if ($token === null) {
            return $next($request);
        }

        foreach ($abilities as $ability) {
            if ($request->user()?->tokenCan($ability)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'This API token is missing the required scope.',
            'required_scopes' => $abilities,
        ], 403);
    }
}
