<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\PortfolioLoggerService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Temporary dev hook: authenticate as the first admin when a shared debug token is sent.
 * DELETE or disable before public launch (LIDO_AGENT_DEBUG_ENABLED=false).
 */
class DebugAgentToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('portfolio.debug_agent.enabled', false)) {
            return $next($request);
        }

        $expected = (string) config('portfolio.debug_agent.token', '');
        if ($expected === '') {
            return $next($request);
        }

        $provided = (string) ($request->header('X-Lido-Debug-Token')
            ?? $request->query('debug_token')
            ?? '');

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return $next($request);
        }

        if ($request->user() !== null) {
            return $next($request);
        }

        $admin = User::query()->where('is_admin', true)->orderBy('id')->first();
        if ($admin === null) {
            return $next($request);
        }

        Auth::guard('web')->login($admin);
        Auth::guard('sanctum')->setUser($admin);
        $request->setUserResolver(static fn () => $admin);

        if (app()->bound(PortfolioLoggerService::class)) {
            app(PortfolioLoggerService::class)->security('warning', 'Debug agent token used for API access', [
                'path' => $request->path(),
                'method' => $request->method(),
                'ip' => $request->ip(),
            ]);
        }

        return $next($request);
    }
}
