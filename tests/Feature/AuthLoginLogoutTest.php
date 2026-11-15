<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature tests para login/logout y la emisión/revocación de token Sanctum
 * (tarea 2.3). Cubre los Requisitos 1.4, 1.5 y 1.6.
 */
class AuthLoginLogoutTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Requisito 1.4: credenciales válidas emiten un token Bearer con la
     * expiración configurada (60 min = 3600 s).
     */
    public function test_login_exitoso_retorna_token_bearer_con_expires_in(): void
    {
        config(['sanctum.expiration' => 60]);

        $user = User::factory()->create([
            'email' => 'cliente@example.com',
            'password' => Hash::make('secret-password'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'cliente@example.com',
            'password' => 'secret-password',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.expires_in', 3600)
            ->assertJsonStructure([
                'data' => ['token', 'token_type', 'expires_in'],
                'message',
            ]);

        $this->assertNotEmpty($response->json('data.token'));

        // El token emitido existe y pertenece al usuario.
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);
    }

    /**
     * Requisito 1.5: credenciales inválidas retornan 401 con un mensaje
     * genérico que no revela cuál campo es incorrecto.
     */
    public function test_login_con_password_incorrecta_retorna_401_generico(): void
    {
        User::factory()->create([
            'email' => 'cliente@example.com',
            'password' => Hash::make('secret-password'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'cliente@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);

        // Mensaje genérico: no debe revelar si el fallo fue email o password.
        $body = $response->getContent();
        $this->assertStringNotContainsStringIgnoringCase('password', $body);
        $this->assertStringNotContainsStringIgnoringCase('contraseña', $body);
        $this->assertStringNotContainsStringIgnoringCase('no existe', $body);
    }

    /**
     * Requisito 1.5: un email inexistente produce la MISMA respuesta genérica
     * 401 que una contraseña incorrecta (no se filtra la existencia de la
     * cuenta).
     */
    public function test_login_con_email_inexistente_retorna_mismo_error_generico(): void
    {
        User::factory()->create([
            'email' => 'cliente@example.com',
            'password' => Hash::make('secret-password'),
        ]);

        $inexistente = $this->postJson('/api/v1/auth/login', [
            'email' => 'nadie@example.com',
            'password' => 'secret-password',
        ]);

        $passwordMala = $this->postJson('/api/v1/auth/login', [
            'email' => 'cliente@example.com',
            'password' => 'otra-cosa',
        ]);

        $inexistente->assertStatus(401);
        $passwordMala->assertStatus(401);

        // Ambos escenarios devuelven el mismo mensaje de error.
        $this->assertSame(
            $inexistente->json('message'),
            $passwordMala->json('message'),
        );
    }

    /**
     * Requisito 1.6: logout revoca el token activo de inmediato; una petición
     * posterior con ese token debe fallar con 401.
     */
    public function test_logout_revoca_el_token_y_peticion_posterior_da_401(): void
    {
        $user = User::factory()->create([
            'email' => 'cliente@example.com',
            'password' => Hash::make('secret-password'),
        ]);

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'cliente@example.com',
            'password' => 'secret-password',
        ])->json('data.token');

        // Con el token el usuario accede a un endpoint protegido.
        $this->withToken($token)->getJson('/api/v1/user')->assertOk();

        // Cierra sesión.
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        // El token quedó revocado inmediatamente.
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Limpia el usuario resuelto que el guard cachea entre peticiones
        // dentro del mismo test (de lo contrario reusa la sesión previa y
        // no vuelve a validar el token contra la base de datos).
        $this->app['auth']->forgetGuards();

        // Una petición posterior con el mismo token ya no está autorizada.
        $this->withToken($token)->getJson('/api/v1/user')->assertStatus(401);
    }

    /**
     * El logout está protegido: sin token válido retorna 401.
     */
    public function test_logout_sin_autenticacion_retorna_401(): void
    {
        $this->postJson('/api/v1/auth/logout')->assertStatus(401);
    }
}
