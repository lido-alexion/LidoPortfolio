<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Select the browser session cookie before Laravel starts the session. */
class SelectAuthSessionContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = $request->header('X-Stox-Auth-Context', 'investor');
        if (! in_array($context, ['investor', 'admin'], true)) {
            abort(400, 'Invalid authentication context.');
        }

        $request->attributes->set('auth_session_context', $context);
        // Keep the legacy investor cookie name so existing native sessions survive.
        if (! app()->bound('stox.base_session_cookie')) {
            app()->instance('stox.base_session_cookie', (string) config('session.cookie'));
        }
        $legacyBase = (string) app('stox.base_session_cookie');
        $cookieName = $context === 'admin' ? $legacyBase.'-admin' : $legacyBase;
        config(['session.cookie' => $cookieName]);
        $previousContext = app()->bound('stox.auth_session_context')
            ? app('stox.auth_session_context')
            : null;
        $resetContextState = ! app()->runningUnitTests()
            || ($previousContext !== null && $previousContext !== $context);
        if ($resetContextState) {
            // Reset on real requests for worker safety, and whenever a test switches
            // contexts. Same-context test requests retain Laravel's cookie-less
            // session behavior unless the test explicitly supplies browser cookies.
            app('session')->forgetDrivers();
            app()->forgetInstance('session.store');
            app('auth')->forgetGuards();
        }
        app()->instance('stox.auth_session_context', $context);

        return $next($request);
    }
}
