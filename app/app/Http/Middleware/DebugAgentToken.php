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
        // This development-only hook can never authenticate in production.
        if ((string) config('app.env') === 'production'
            || app()->environment('production')
            || ! config('portfolio.debug_agent.enabled', false)) {
            return $next($request);
        }

        $expected = (string) config('portfolio.debug_agent.token', '');
        if ($expected === '') {
            return $next($request);
        }

        $provided = (string) $request->header('X-Lido-Debug-Token', '');

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

        Auth::guard('sanctum')->setUser($admin);
        $request->setUserResolver(static fn () => $admin);
        // This legacy developer hook is an API credential that synthesizes an
        // admin principal. It is not a browser session selected by the
        // X-Stox-Auth-Context cookie, so the browser role/context binding must
        // not reinterpret it as an investor session.
        $request->attributes->set('stox_debug_agent_auth', true);

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
