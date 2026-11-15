<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Servicio de autenticación (Autenticador del dominio).
 *
 * -----------------------------------------------------------------------------
 * NOTA DE COORDINACIÓN (tareas en paralelo 2.1 / 2.3)
 * -----------------------------------------------------------------------------
 * Esta clase es compartida entre varias tareas del plan de implementación:
 *   - Tarea 2.1: register() + verificación/reenvío de correo.
 *   - Tarea 2.3 (ESTE archivo): login() y logout().
 *
 * Al momento de implementar la tarea 2.3 el archivo aún no existía, por lo que
 * se crea SOLO con los métodos login() y logout(). Si la tarea 2.1 ya añadió
 * un register() en su propia versión, al fusionar basta con copiar ESE método
 * dentro de esta clase (o viceversa): no hay dependencias cruzadas entre
 * register() y login()/logout(). No se debe borrar ningún método existente.
 * -----------------------------------------------------------------------------
 */
class AuthService
{
    /**
     * Rol asignado automáticamente a todo usuario recién registrado
     * (Requisito 2.1: doble perfil, el rol base es "cliente").
     */
    public const DEFAULT_ROLE = 'cliente';

    // --- Tarea 2.1 (registro / verificación de correo) ---------------------

    /**
     * Registra un nuevo usuario.
     *
     * Crea la cuenta en estado "pendiente de verificación" (sin
     * `email_verified_at`), le asigna el rol `cliente` y dispara el
     * evento `Registered` de Laravel, cuyo listener por defecto
     * (`SendEmailVerificationNotification`) envía el correo de
     * verificación al usuario (Requisitos 1.1, 1.2 y 2.1).
     *
     * Toda la escritura en base de datos se envuelve en una transacción
     * para evitar dejar una cuenta sin su rol si algo falla a mitad de
     * camino.
     *
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function register(array $data): User
    {
        $user = DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            // Asignación del rol base "cliente" (Requisito 2.1).
            $user->assignRole(self::DEFAULT_ROLE);

            return $user;
        });

        // Dispara el envío del correo de verificación (listener por defecto
        // SendEmailVerificationNotification). Se hace fuera de la transacción
        // para no retener la conexión mientras se encola/envía el correo.
        event(new Registered($user));

        return $user;
    }

    // --- Tarea 2.3 (login / logout) ----------------------------------------

    /**
     * Valida credenciales y emite un token Bearer de Sanctum.
     *
     * El token hereda la expiración de 60 minutos configurada globalmente en
     * config/sanctum.php (`sanctum.expiration`). La respuesta sigue el formato
     * del diseño: { token, token_type: "Bearer", expires_in }.
     *
     * Ante credenciales inválidas lanza una ValidationException con un mensaje
     * GENÉRICO (sin revelar si el email no existe o la contraseña es incorrecta)
     * que el manejador de excepciones traduce a HTTP 401 mediante el helper de
     * abajo. Además registra el intento fallido en el log (Requisito 1.5).
     *
     * @param  array{email: string, password: string}  $credentials
     * @return array{token: string, token_type: string, expires_in: int}
     *
     * @throws ValidationException
     */
    public function login(array $credentials, ?string $ip = null): array
    {
        $user = User::where('email', $credentials['email'])->first();

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            // Registrar el intento fallido sin exponer la contraseña (Requisito 1.5).
            Log::warning('Intento de inicio de sesión fallido.', [
                'email' => $credentials['email'],
                'ip' => $ip,
            ]);

            // Mensaje genérico: no se revela cuál campo es incorrecto.
            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ])->status(401);
        }

        // Emite el token Bearer; la expiración (60 min) la aplica Sanctum
        // a partir de config('sanctum.expiration').
        $token = $user->createToken('auth-token')->plainTextToken;

        $expirationMinutes = (int) config('sanctum.expiration', 60);

        return [
            'token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $expirationMinutes * 60,
        ];
    }

    /**
     * Revoca de inmediato el token de acceso activo del usuario (Requisito 1.6).
     *
     * Elimina únicamente el personal access token con el que se autenticó la
     * petición actual, dejando intactos otros tokens que el usuario pudiera
     * tener en otras sesiones/dispositivos.
     */
    public function logout(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token !== null) {
            $token->delete();
        }
    }
}
