<?php

require_once dirname(__DIR__).'/app/Support/helpers.php';

use App\Engines\Support\ApiEnvelope;
use App\Exceptions\DomainException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\LidoTelemetryHttpMiddleware;
use App\Http\Middleware\DebugAgentToken;
use App\Http\Middleware\EnsurePersonalApiTokenScope;
use App\Http\Middleware\EnsureIntradayBackfillInternalToken;
use App\Http\Middleware\EnsureMicrostructureCollectorInternalToken;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\ResolveActivePortfolio;
use App\Services\PortfolioLoggerService;
use App\Services\Operations\ApiFailureReporter;
use App\Services\Operations\LogErrorTriageService;
use App\Support\ApiErrorMessage;
use App\Support\ProductionEnvironment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$application = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->prependToGroup('api', DebugAgentToken::class);
        $middleware->appendToGroup('api', AssignRequestId::class);
        $middleware->appendToGroup('api', LidoTelemetryHttpMiddleware::class);
        $middleware->appendToGroup('web', AssignRequestId::class);
        $middleware->appendToGroup('web', LidoTelemetryHttpMiddleware::class);
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
            'microstructure.collector.internal' => EnsureMicrostructureCollectorInternalToken::class,
            'intraday.backfill.internal' => EnsureIntradayBackfillInternalToken::class,
            'active.portfolio' => ResolveActivePortfolio::class,
            'token.scope' => EnsurePersonalApiTokenScope::class,
        ]);
        $middleware->appendToGroup('api', ResolveActivePortfolio::class);
        $middleware->priority([
            ResolveActivePortfolio::class,
            SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->report(function (Throwable $e): void {
            if (app()->bound(PortfolioLoggerService::class)) {
                app(PortfolioLoggerService::class)->api('error', $e->getMessage(), [
                    'exception' => $e::class,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
            if (! $e instanceof \Illuminate\Validation\ValidationException) {
                app(LogErrorTriageService::class)->observe($e);
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof ValidationException) {
                return null;
            }

            // TD-010: domain preconditions → Trading OS ApiEnvelope (422/400).
            if ($e instanceof DomainException) {
                return ApiEnvelope::error($e->errorCode(), $e->getMessage(), $e->httpStatus());
            }

            // Let the framework render 401/403 (AuthenticationException and
            // AuthorizationException are not HttpExceptionInterface, so the
            // generic branch below would wrongly report them as 500).
            if ($e instanceof AuthenticationException
                || $e instanceof AuthorizationException) {
                return null;
            }

            if ($e instanceof HttpResponseException) {
                return $e->getResponse();
            }

            $requestId = $request->headers->get('X-Request-ID');
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $message = ApiErrorMessage::for($e);

            if ($status >= 500 && ! $request->headers->has('X-StoX-Skip-Api-Failure-Reporting')) {
                app(ApiFailureReporter::class)->observeApiFailure(
                    $request->method(),
                    '/'.$request->path(),
                    $status,
                    $message,
                    ['trace_id' => $requestId],
                );
            }

            return response()->json([
                'message' => $message,
                'request_id' => $requestId,
            ], $status)->header('X-Request-ID', (string) $requestId);
        });
    })->create();

// FEAT-031: production secrets may live outside public_html in
// /home/USER/config/LidoPortfolio.env. An explicit process-level path wins;
// otherwise walk ancestors so both legacy and single-folder cPanel layouts work.
$explicitEnvironmentPath = getenv('LIDO_ENV_PATH');
$environmentFile = ProductionEnvironment::resolve(
    dirname(__DIR__),
    is_string($explicitEnvironmentPath) ? $explicitEnvironmentPath : null,
);
if ($environmentFile !== null) {
    $application->useEnvironmentPath(dirname($environmentFile));
    $application->loadEnvironmentFrom(basename($environmentFile));
}

return $application;
