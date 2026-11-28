<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/** Require a session-bound token even when Fetch Metadata says same-origin. */
class RequireSessionCsrfToken extends ValidateCsrfToken
{
    /**
     * Fetch Metadata is useful defense in depth, but it is not a substitute for
     * the explicit per-session token contract used by StoX browser clients.
     */
    protected function hasValidOrigin($request)
    {
        return false;
    }
}
