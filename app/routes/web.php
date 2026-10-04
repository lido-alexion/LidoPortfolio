<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

// A small server-rendered entry point for the daily collector login workflow.
// Status and login URL remain behind the same Sanctum session auth as the SPA.
Route::get('/kite-connect', function () {
    $user = Auth::guard('web')->user();
    if ($user === null) {
        $base = rtrim(parse_url(config('app.url'), PHP_URL_PATH) ?? '', '/');
        return redirect(($base === '' ? '' : $base).'/login');
    }
    abort_unless((int) config('microstructure_collector.kite_user_id') > 0
        && (int) $user->id === (int) config('microstructure_collector.kite_user_id'), 403);

    return response()->view('kite-connect');
});

/*
| SPA fallback: React Router (BrowserRouter) handles client paths.
| Without this, refreshing /holdings, /transactions, etc. returns 404 from Laravel.
| Never match /api/* — unknown API paths must 404 as JSON, not return this HTML shell.
*/
Route::view('/{any?}', 'app')->where('any', '^(?!api(?:/|$)).*');
