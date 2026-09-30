<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class MlAcceptanceRequestLimit
{
    public function handle(Request $request, Closure $next)
    {
        abort_if((int) $request->server('CONTENT_LENGTH', 0) > 1500000, 413, 'Acceptance request exceeds upload chunk limit.');
        return $next($request);
    }
}
