<?php

use Illuminate\Support\Facades\Route;

/*
| SPA fallback: React Router (BrowserRouter) handles client paths.
| Without this, refreshing /holdings, /transactions, etc. returns 404 from Laravel.
*/
Route::view('/{any?}', 'app')->where('any', '.*');
