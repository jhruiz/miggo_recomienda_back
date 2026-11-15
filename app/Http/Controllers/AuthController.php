<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Responses\HasApiResponses;
use App\Models\User;
use App\Services\AuthService;
use App\Services\ProfileService;
use App\Support\LoginRateLimiter;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controlador de autenticación (/api/v1/auth).
 *
 * -----------------------------------------------------------------------------
 * NOTA DE COORDINACIÓN (tareas en paralelo 2.1 / 2.3)
 * -----------------------------------------------------------------------------
 * Este controlador es compartido:
 *   - Tarea 2.1: register(), verify(), resend() (verificación de correo).
 *   - Tarea 2.3 (ESTE archivo): login() y logout().
 *
 * Al implementar la tarea 2.3 el archivo aún no existía; se crea SOLO con
 * login() y logout(). Al fusionar con la versión de la tarea 2.1, copiar sus
 * métodos (register/verify/resend) dentro de esta clase sin borrar los de
 * abajo. El constructor inyecta el mismo AuthService compartido.
 * -----------------------------------------------------------------------------
 */
class AuthController extends Controller
{
    use HasApiResponses;

    public function __construct(
        private readonly AuthService $authService,
        private readonly ProfileService $profileService,
    ) {
    }

    // --- Tarea 2.1 (registro / verificación de correo) ---------------------

    /**
     * POST /api/v1/auth/register
     *
     * Registra un nuevo usuario en estado "pendiente de verificación",
     * le asigna el rol `cliente` y dispara el correo de verificación.
     *
     * Las reglas de validación (nombre ≤100, email único válido,
     * password 8-72) se aplican en RegisterRequest; un fallo de
     * validación retorna HTTP 422 con el envelope estándar (incluyendo
     * el mensaje "El correo electrónico ya está en uso" para duplicados).
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->authService->register($request->validated());

        return $this->created(
            data: [
                'user' => $this->profileService->presentIdentity($user),
            ],
            message: 'Registro exitoso. Revisa tu correo para verificar la cuenta.',
        );
    }

    /**
     * POST /api/v1/auth/email/verify/{id}/{hash}
     *
     * Verifica el correo electrónico de un usuario a partir del enlace
     * firmado enviado por correo. Marca `email_verified_at` y emite el
     * evento Verified. Endpoint público (el enlace va firmado).
     */
    public function verifyEmail(Request $request, string $id, string $hash): JsonResponse
    {
        $user = User::find($id);

        if ($user === null) {
            return $this->error('Usuario no encontrado.', status: 404);
        }

        // El hash del enlace es sha1 del correo del usuario; valida que
        // el enlace corresponda realmente a este usuario.
        if (! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            return $this->error('El enlace de verificación no es válido.', status: 403);
        }

        if ($user->hasVerifiedEmail()) {
            return $this->ok(
                data: ['email_verified' => true],
                message: 'El correo electrónico ya estaba verificado.',
            );
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return $this->ok(
            data: ['email_verified' => true],
            message: 'Correo electrónico verificado correctamente.',
        );
    }

    /**
     * POST /api/v1/auth/email/resend  (auth:sanctum)
     *
     * Reenvía el correo de verificación al usuario autenticado.
     */
    public function resendVerification(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return $this->error('No autenticado.', status: 401);
        }

        if ($user->hasVerifiedEmail()) {
            return $this->ok(
                data: ['email_verified' => true],
                message: 'El correo electrónico ya está verificado.',
            );
        }

        $user->sendEmailVerificationNotification();

        return $this->ok(
            message: 'Correo de verificación reenviado.',
        );
    }


    // --- Tarea 2.3 (login / logout) ----------------------------------------

    /**
     * POST /api/v1/auth/login
     *
     * Valida credenciales y, si son correctas, retorna un token Bearer con su
     * expiración. Ante credenciales inválidas, AuthService lanza una
     * ValidationException (HTTP 401) con mensaje genérico (Requisito 1.5).
     */
    public function login(LoginRequest $request): JsonResponse
    {
        // Rate limiting por IP (tarea 2.5 / Requisito 1.7): la ruta aplica el
        // middleware `throttle:login` que bloquea con HTTP 429 tras 5 intentos.
        // El middleware incrementa el contador en cada request; si las
        // credenciales son inválidas AuthService::login() lanza antes de llegar
        // a la línea de abajo, por lo que ese intento queda contado. Un login
        // EXITOSO limpia el contador, de modo que solo los intentos FALLIDOS
        // cuentan contra el límite.
        $result = $this->authService->login(
            $request->validated(),
            $request->ip(),
        );

        LoginRateLimiter::clear($request);

        return $this->ok($result, 'Inicio de sesión exitoso.');
    }

    /**
     * POST /api/v1/auth/logout  (auth:sanctum)
     *
     * Revoca el token activo del usuario autenticado de forma inmediata
     * (Requisito 1.6).
     */
    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return $this->ok(null, 'Sesión cerrada correctamente.');
    }
}
