<?php

use App\Http\Responses\ApiResponse;
use Illuminate\Auth\AuthenticationException;
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
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Autorización de canales privados (Echo/Reverb) bajo /api/broadcasting/auth
    // con token Bearer de Sanctum: el frontend es una SPA en otro origen y no
    // usa la sesión web, que es la que aplicaría el registro por defecto.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Alias para el middleware de autorización por rol activo de Prestador.
        // Aplicar tras la autenticación: ['auth:sanctum', 'role.provider'].
        $middleware->alias([
            'role.provider' => \App\Http\Middleware\EnsureProviderRole::class,
        ]);

        // EnsureProviderRole debe ejecutarse antes de SubstituteBindings para que
        // un usuario sin rol de Prestador reciba 403 aunque el id no exista (y no 404).
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\EnsureProviderRole::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Devuelve JSON con el envelope estándar para las peticiones de la API.
        $shouldRenderJson = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($shouldRenderJson);

        $exceptions->render(function (ValidationException $e, Request $request) use ($shouldRenderJson) {
            if (! $shouldRenderJson($request)) {
                return null;
            }

            return ApiResponse::error(
                message: $e->getMessage(),
                errors: $e->errors(),
                status: $e->status,
            );
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($shouldRenderJson) {
            if (! $shouldRenderJson($request)) {
                return null;
            }

            return ApiResponse::error(
                message: 'No autenticado.',
                status: 401,
            );
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($shouldRenderJson) {
            if (! $shouldRenderJson($request)) {
                return null;
            }

            return ApiResponse::error(
                message: $e->getMessage() !== '' ? $e->getMessage() : 'Error de solicitud.',
                status: $e->getStatusCode(),
            );
        });
    })->create();
