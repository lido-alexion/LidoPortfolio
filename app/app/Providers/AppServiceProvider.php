<?php

namespace App\Providers;

use App\Services\Artifacts\ArtifactRegistry;
use App\Services\Artifacts\ArtifactValidationService;
use App\Services\Artifacts\IndicatorArtifactRegistry;
use App\Services\Artifacts\ScreenerArtifactRegistry;
use App\Services\Artifacts\StrategyArtifactRegistry;
use App\Services\AccessRequest\HumanVerificationService;
use App\Services\AccessRequest\TestingHumanVerificationService;
use App\Services\AccessRequest\TurnstileHumanVerificationService;
use App\Services\Fundamentals\FundamentalDataProvider;
use App\Services\Fundamentals\YahooFundamentalDataProvider;
use App\Services\Indicators\IndicatorRegistry;
use App\Services\Indicators\IndicatorRegistryFactory;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use App\Telemetry\LidoTelemetry;
use App\Telemetry\LidoTelemetryCatalog;
use App\Telemetry\TraceContext;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
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
        $this->app->bind(HumanVerificationService::class, function () {
            $driver = (string) config('access_requests.captcha.driver', 'turnstile');

            // A deployment mistake must never turn production into an
            // accept-any-token CAPTCHA environment.
            if (config('app.env') === 'production' && $driver === 'testing') {
                $driver = 'turnstile';
            }

            return match ($driver) {
                'testing' => new TestingHumanVerificationService,
                default => new TurnstileHumanVerificationService,
            };
        });

        $this->app->singleton(IndicatorRegistry::class, function () {
            return (new IndicatorRegistryFactory)->make();
        });
        $this->app->singleton(ArtifactValidationService::class);
        $this->app->singleton(IndicatorArtifactRegistry::class);
        $this->app->singleton(ScreenerArtifactRegistry::class);
        $this->app->singleton(StrategyArtifactRegistry::class);
        $this->app->singleton(ArtifactRegistry::class);
        $this->app->bind(FundamentalDataProvider::class, YahooFundamentalDataProvider::class);
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

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($appHost) && $appHost !== '') {
            $apex = preg_replace('/^www\./i', '', $appHost);
            $hosts = array_values(array_unique(array_filter([
                $appHost,
                $apex,
                $apex !== '' ? "www.{$apex}" : null,
                ...array_map('trim', explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', ''))),
            ])));
            config(['sanctum.stateful' => $hosts]);
        }

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

        RateLimiter::for('access-request', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email', '')));

            return [
                Limit::perMinute(6)->by($request->ip()),
                Limit::perHour(12)->by($email !== '' ? 'email:'.$email : $request->ip()),
            ];
        });

        RateLimiter::for('access-request-verify', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });

        RateLimiter::for('universe-price-sync', function (Request $request) {
            return Limit::perMinute(12)->by($request->user()?->id ?: $request->ip());
        });

        $this->registerTelemetryListeners();
    }

    protected function registerTelemetryListeners(): void
    {
        Queue::createPayloadUsing(function (): array {
            $context = TraceContext::active();

            return $context === null
                ? []
                : ['stox_traceparent' => $context->childSpan()->traceparent()];
        });

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $payload = $event->job->payload();
            $parent = is_array($payload) ? ($payload['stox_traceparent'] ?? null) : null;
            TraceContext::activate(is_string($parent) ? TraceContext::fromRequest($parent) : TraceContext::freshRoot());
            app(LidoTelemetry::class)->recordBusinessEvent(LidoTelemetryCatalog::BUSINESS_QUEUE_JOB, [
                'job' => class_basename($event->job->resolveName()),
                'phase' => 'processing',
            ]);
        });

        Event::listen(JobProcessed::class, function (JobProcessed $event): void {
            app(LidoTelemetry::class)->recordBusinessEvent(LidoTelemetryCatalog::BUSINESS_QUEUE_JOB, [
                'job' => class_basename($event->job->resolveName()),
                'phase' => 'processed',
            ]);
            TraceContext::clear();
        });

        Event::listen(JobFailed::class, function (JobFailed $event): void {
            app(LidoTelemetry::class)->recordBusinessEvent(LidoTelemetryCatalog::BUSINESS_QUEUE_JOB, [
                'job' => class_basename($event->job->resolveName()),
                'phase' => 'failed',
            ]);
            TraceContext::clear();
        });

        Event::listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event): void {
            TraceContext::activate(TraceContext::freshRoot());
            app(LidoTelemetry::class)->recordBusinessEvent(LidoTelemetryCatalog::BUSINESS_SCHEDULER_TASK, [
                'task' => $event->task->description ?? $event->task->command ?? 'scheduled',
            ]);
        });

        Event::listen(ScheduledTaskFinished::class, function (): void {
            TraceContext::clear();
        });

        Event::listen(ScheduledTaskFailed::class, function (): void {
            TraceContext::clear();
        });
    }
}
