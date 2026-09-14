<?php

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\CheckRole;
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
        $middleware->alias([
            'role' => CheckRole::class,
            'permission' => CheckPermission::class,
        ]);

        $middleware->api(prepend: [
            'throttle:api',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (\Throwable $e, Request $request) {
            if (!($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'status'  => 'error',
                    'message' => $e->errors(),
                    'data'    => null,
                ], 422);
            }

            $status = $e instanceof HttpExceptionInterface
                ? $e->getStatusCode()
                : 500;

            $message = $e instanceof HttpExceptionInterface
                ? ($e->getMessage() ?: 'http-error')
                : (config('app.debug') ? $e->getMessage() : 'internal-error');

            return response()->json([
                'status'  => 'error',
                'message' => $message,
                'data'    => null,
            ], $status);
        });
    })->create();
