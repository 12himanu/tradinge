<?php

declare(strict_types=1);

use App\Domain\Chart\Exceptions\ChartImageRejected;
use App\Presentation\Console\Commands\ImportMerolaganiSymbolsCommand;
use App\Presentation\Console\Commands\ScanMarketCommand;
use App\Presentation\Console\Commands\SyncMerolaganiCommand;
use App\Presentation\Console\Commands\SyncSharesansarCommand;
use App\Presentation\Http\ApiError;
use App\Presentation\Http\Middleware\AssignRequestId;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api_v1.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    // Commands live under app/Presentation/Console, outside Laravel's auto-discovered directory.
    ->withCommands([
        ImportMerolaganiSymbolsCommand::class,
        ScanMarketCommand::class,
        SyncMerolaganiCommand::class,
        SyncSharesansarCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA cookie auth for requests coming from the Next.js frontend.
        $middleware->statefulApi();
        $middleware->throttleApi();
        // Local stack runs behind the Next.js dev proxy / Docker networking.
        $middleware->trustProxies(at: '*');
        $middleware->append(AssignRequestId::class);
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson()
        );

        // Consistent {"error": {code, message, details}} envelope for the public API.
        // Validation errors keep Laravel's standard 422 format.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return $request->is('api/*')
                ? ApiError::response('UNAUTHENTICATED', 'Authentication required.', 401)
                : null;
        });

        // Rejected screenshots are client errors: answer with their code and status, don't log them.
        $exceptions->dontReport([ChartImageRejected::class]);
        $exceptions->render(
            fn (ChartImageRejected $e) => ApiError::response($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details)
        );

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            return $request->is('api/*') ? ApiError::fromHttpException($e) : null;
        });
    })->create();
