<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\TransientToken;
use Symfony\Component\HttpFoundation\Response;

/** Bind cookie-authenticated identities to the selected browser context. */
class EnsureAuthSessionRole
{
    public function handle(Request $request, Closure $next): Response
    {
        // Resolve through Sanctum so both cookie sessions and personal tokens
        // receive the same boundary check. Explicit tokens are exempt below.
        $user = Auth::guard('sanctum')->user();
        $context = $request->attributes->get('auth_session_context', 'investor');

        // Explicit API tokens are not browser sessions and retain their own scopes.
        $accessToken = $user?->currentAccessToken();
        $isBrowserSession = $accessToken === null || $accessToken instanceof TransientToken;
        if ($user !== null && $isBrowserSession
            && (bool) $user->is_admin !== ($context === 'admin')) {
            abort(403, 'This account belongs to a different sign-in context.');
        }

        // The SPA sends the identity it rendered the action for. A same-role
        // login in another tab can replace the shared context cookie while an
        // old tab is still open; reject its stale writes before controllers run.
        if ($user !== null && $isBrowserSession && $request->headers->has('X-Stox-Auth-User')) {
            $expectedUser = (string) $request->header('X-Stox-Auth-User');
            if ($expectedUser === 'guest' || $expectedUser !== (string) $user->getAuthIdentifier()) {
                abort(401, 'The signed-in account changed. Reload and sign in again.');
            }
        }

        return $next($request);
    }
}
