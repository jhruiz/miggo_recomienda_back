<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de autorización por rol activo de Prestador (tarea 4.3).
 *
 * Rechaza las acciones restringidas al rol `prestador` cuando el usuario
 * autenticado NO tiene ese rol, retornando un error de autorización
 * (HTTP 403) con el envelope estándar { data, message, errors? } y un
 * mensaje claro indicando que debe activar el rol de Prestador.
 *
 * El middleware NO modifica ningún estado: únicamente autoriza o rechaza
 * la petición, preservando el estado previo de la sesión (Requisito 2.6).
 *
 * Alias registrado: `role.provider` (ver bootstrap/app.php).
 *
 * Uso en rutas (en tareas futuras):
 *   Route::post('/quotes', [QuoteController::class, 'store'])
 *       ->middleware(['auth:sanctum', 'role.provider']);
 *
 * Debe aplicarse SIEMPRE después de un middleware de autenticación
 * (p. ej. `auth:sanctum`), de modo que un usuario no autenticado obtenga
 * primero un 401 antes de llegar a esta comprobación de 403.
 */
class EnsureProviderRole
{
    /**
     * El rol requerido para las acciones protegidas por este middleware.
     */
    private const REQUIRED_ROLE = 'prestador';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Sin usuario autenticado no podemos evaluar el rol: delegamos en
        // la capa de autenticación devolviendo 401. En la práctica este
        // middleware se encadena tras `auth:sanctum`, por lo que este caso
        // solo ocurre si se aplica sin autenticación previa.
        if ($user === null) {
            return ApiResponse::error(
                message: 'No autenticado.',
                status: 401,
            );
        }

        if (! $user->hasRole(self::REQUIRED_ROLE)) {
            return ApiResponse::error(
                message: 'Debes activar el rol de Prestador para realizar esta acción.',
                status: 403,
            );
        }

        return $next($request);
    }
}
