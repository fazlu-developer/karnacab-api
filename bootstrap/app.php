<?php

use App\Http\Middleware\AuthenticateJwt;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require_once __DIR__.'/show_errors.php';

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'jwt' => AuthenticateJwt::class,
        ]);
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                if (function_exists('karnacab_debug_wanted') && karnacab_debug_wanted()) {
                    return response(
                        karnacab_error_html(
                            $e::class,
                            $e->getMessage() !== '' ? $e->getMessage() : '(no message)',
                            $e->getFile(),
                            $e->getLine(),
                            $e->getTraceAsString(),
                        ),
                        500,
                    )->header('Content-Type', 'text/html; charset=utf-8');
                }

                return null;
            }
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                $status = 422;
            }
            if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                $status = 404;
            }

            return response()->json([
                'message' => $e->getMessage() !== '' ? $e->getMessage() : $e::class,
                'statusCode' => $status,
                'error' => $e::class,
            ], $status);
        });
    })->create();
