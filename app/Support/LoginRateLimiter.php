<?php

namespace App\Support;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Rate limiting de intentos de login por IP (Requisito 1.7).
 *
 * Reglas:
 * - Máximo 5 intentos FALLIDOS por IP en una ventana de 15 minutos.
 * - Al superarse, la IP queda bloqueada 15 minutos.
 * - La respuesta de bloqueo es HTTP 429 con header `Retry-After` y un
 *   mensaje que indica el tiempo restante de bloqueo, usando el envelope
 *   estándar `{ data, message, errors? }`.
 *
 * El contador se respalda en el store de caché configurado (Redis en
 * producción vía Laragon; `array` en el entorno de testing según
 * phpunit.xml). Redis provee contadores atómicos sin condiciones de
 * carrera, como indica la decisión técnica 7 del diseño.
 *
 * Esta clase es independiente del código de negocio de AuthService: el
 * controlador de login (tarea 2.3) la invoca para registrar los intentos
 * fallidos y limpiar el contador tras un login exitoso. La verificación
 * previa (bloqueo) la realiza el middleware `throttle:login` definido en
 * AppServiceProvider mediante `RateLimiter::for('login', ...)`.
 */
final class LoginRateLimiter
{
    /**
     * Número máximo de intentos fallidos permitidos por IP.
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * Duración de la ventana / bloqueo en segundos (15 minutos).
     */
    public const DECAY_SECONDS = 15 * 60;

    /**
     * Nombre del limiter (debe coincidir con RateLimiter::for('login', ...)).
     */
    public const NAME = 'login';

    /**
     * Clave de caché del limiter para una request, basada en la IP.
     *
     * Debe coincidir EXACTAMENTE con la clave que calcula el middleware
     * `throttle:login` (Illuminate\Routing\Middleware\ThrottleRequests), que
     * es `md5($limiterName.$limit->key)` donde `$limit->key` es el valor de
     * `->by()` del limiter (aquí, la IP). Así, el `clear()` tras un login
     * exitoso reinicia el MISMO contador que incrementa el middleware, de modo
     * que solo los intentos fallidos cuentan contra el límite.
     */
    public static function key(Request $request): string
    {
        return md5(self::NAME.$request->ip());
    }

    /**
     * ¿La IP ya superó el máximo de intentos fallidos permitidos?
     */
    public static function tooManyAttempts(Request $request): bool
    {
        return RateLimiter::tooManyAttempts(self::key($request), self::MAX_ATTEMPTS);
    }

    /**
     * Registra un intento de login FALLIDO para la IP de la request.
     *
     * Debe invocarse únicamente cuando las credenciales son inválidas,
     * para que solo los intentos fallidos cuenten contra el límite.
     */
    public static function hit(Request $request): void
    {
        RateLimiter::hit(self::key($request), self::DECAY_SECONDS);
    }

    /**
     * Limpia el contador de intentos tras un login exitoso.
     */
    public static function clear(Request $request): void
    {
        RateLimiter::clear(self::key($request));
    }

    /**
     * Segundos restantes de bloqueo antes de poder reintentar.
     */
    public static function availableIn(Request $request): int
    {
        return RateLimiter::availableIn(self::key($request));
    }

    /**
     * Construye la respuesta 429 de bloqueo con el envelope estándar.
     *
     * Incluye el header `Retry-After` (segundos) y un mensaje legible que
     * indica el tiempo restante de bloqueo.
     */
    public static function lockoutResponse(int $retryAfterSeconds): JsonResponse
    {
        $minutes = (int) ceil($retryAfterSeconds / 60);

        $message = sprintf(
            'Demasiados intentos de inicio de sesión. Intenta nuevamente en %d %s.',
            $minutes,
            Str::plural('minuto', $minutes),
        );

        return ApiResponse::error(
            message: $message,
            errors: ['retry_after' => [$retryAfterSeconds]],
            status: 429,
            meta: ['retry_after' => $retryAfterSeconds],
        )->header('Retry-After', (string) $retryAfterSeconds);
    }
}
