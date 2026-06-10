<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        if ($rootUrl = config('app.url')) {
            URL::forceRootUrl($rootUrl);
            if (str_starts_with($rootUrl, 'https://')) {
                URL::forceScheme('https');
            }
        }

        // Root-relative /portfolio/build/... URLs — works on www and non-www (absolute APP_URL
        // host in <script type="module"> breaks on the other hostname without CORS).
        Vite::createAssetPathsUsing(function (string $path, ?bool $secure = null): string {
            $appPath = parse_url((string) config('app.url'), PHP_URL_PATH) ?: '';
            $appPath = rtrim($appPath, '/');
            $relative = ltrim($path, '/');

            return ($appPath !== '' ? $appPath.'/' : '/').$relative;
        });

        RateLimiter::for('stock-search', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('stock-validate', function (Request $request) {
            return Limit::perMinute(15)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('analytics-explore', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }
}
