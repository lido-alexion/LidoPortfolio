<?php

require_once dirname(__DIR__).'/app/Support/helpers.php';

use App\Http\Middleware\AssignRequestId;
use App\Services\PortfolioLoggerService;
use App\Support\ApiErrorMessage;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->appendToGroup('api', AssignRequestId::class);
        $middleware->appendToGroup('web', AssignRequestId::class);
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'active.portfolio' => \App\Http\Middleware\ResolveActivePortfolio::class,
        ]);
        $middleware->appendToGroup('api', \App\Http\Middleware\ResolveActivePortfolio::class);
        $middleware->priority([
            \App\Http\Middleware\ResolveActivePortfolio::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->report(function (\Throwable $e): void {
            if (app()->bound(PortfolioLoggerService::class)) {
                app(PortfolioLoggerService::class)->api('error', $e->getMessage(), [
                    'exception' => $e::class,
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);
            }
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof ValidationException) {
                return null;
            }

            if ($e instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                return $e->getResponse();
            }

            $requestId = $request->headers->get('X-Request-ID');
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $message = ApiErrorMessage::for($e);

            return response()->json([
                'message' => $message,
                'request_id' => $requestId,
            ], $status)->header('X-Request-ID', (string) $requestId);
        });
    })->create();
