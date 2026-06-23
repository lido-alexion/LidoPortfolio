<?php

use Illuminate\Support\Facades\Route;

/*
| SPA fallback: React Router (BrowserRouter) handles client paths.
| Without this, refreshing /holdings, /transactions, etc. returns 404 from Laravel.
| Never match /api/* — unknown API paths must 404 as JSON, not return this HTML shell.
*/
Route::view('/{any?}', 'app')->where('any', '^(?!api(?:/|$)).*');
