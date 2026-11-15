<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\LoginRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Feature tests para el rate limiting de login por IP (tarea 2.5).
 *
 * Cubre el Requisito 1.7: tras 5 intentos de inicio de sesión fallidos
 * consecutivos desde la misma IP en 15 minutos, el acceso desde esa IP se
 * bloquea 15 minutos, retornando HTTP 429 con header `Retry-After` y un
 * mensaje que indica el tiempo restante de bloqueo, usando el envelope
 * estándar { data, message, errors? }.
 *
 * El contador se respalda en el store de caché configurado. En testing es
 * `array` (ver phpunit.xml, CACHE_STORE=array), por lo que no se requiere
 * Redis para ejecutar estas pruebas.
 */
class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Garantiza un contador limpio por IP entre pruebas. La clave coincide
        // con la que usan el middleware throttle:login y LoginRateLimiter.
        RateLimiter::clear(md5('login127.0.0.1'));
    }

    /**
     * Requisito 1.7: el 6º intento tras 5 fallidos desde la misma IP retorna
     * 429 con header `Retry-After` y un mensaje de bloqueo con el envelope
     * estándar.
     */
    public function test_sexto_intento_fallido_desde_misma_ip_retorna_429_con_retry_after(): void
    {
        User::factory()->create([
            'email' => 'cliente@example.com',
            'password' => Hash::make('secret-password'),
        ]);

        $invalido = [
            'email' => 'cliente@example.com',
            'password' => 'password-incorrecta',
        ];

        // 5 intentos fallidos permitidos: todos responden 401 (credenciales).
        for ($i = 1; $i <= LoginRateLimiter::MAX_ATTEMPTS; $i++) {
            $this->postJson('/api/v1/auth/login', $invalido)
                ->assertStatus(401);
        }

        // El 6º intento queda bloqueado por el rate limiter.
        $response = $this->postJson('/api/v1/auth/login', $invalido);

        $response->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonStructure([
                'data',
                'message',
                'errors',
                'meta' => ['retry_after'],
            ]);

        // El mensaje indica el tiempo restante de bloqueo (en minutos).
        $this->assertStringContainsStringIgnoringCase('minuto', $response->json('message'));

        // El header `Retry-After` es un número de segundos positivo y acotado
        // a la ventana de bloqueo (15 min = 900 s).
        $retryAfter = (int) $response->headers->get('Retry-After');
        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(LoginRateLimiter::DECAY_SECONDS, $retryAfter);
    }

    /**
     * Requisito 1.7: el bloqueo es por IP. Una IP distinta no queda bloqueada
     * por los intentos fallidos de otra.
     */
    public function test_el_bloqueo_es_por_ip_otra_ip_no_queda_bloqueada(): void
    {
        User::factory()->create([
            'email' => 'cliente@example.com',
            'password' => Hash::make('secret-password'),
        ]);

        $invalido = [
            'email' => 'cliente@example.com',
            'password' => 'password-incorrecta',
        ];

        // Agota el límite desde la IP por defecto (127.0.0.1).
        for ($i = 1; $i <= LoginRateLimiter::MAX_ATTEMPTS; $i++) {
            $this->postJson('/api/v1/auth/login', $invalido)->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/login', $invalido)->assertStatus(429);

        // Desde otra IP el acceso sigue disponible (401 por credenciales, no 429).
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->postJson('/api/v1/auth/login', $invalido)
            ->assertStatus(401);
    }

    /**
     * Un login EXITOSO limpia el contador: los intentos fallidos previos no
     * acumulan hacia el bloqueo una vez que el usuario ingresa correctamente.
     */
    public function test_login_exitoso_reinicia_el_contador_de_intentos(): void
    {
        User::factory()->create([
            'email' => 'cliente@example.com',
            'password' => Hash::make('secret-password'),
        ]);

        // 4 intentos fallidos (uno por debajo del límite).
        for ($i = 1; $i <= LoginRateLimiter::MAX_ATTEMPTS - 1; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'cliente@example.com',
                'password' => 'password-incorrecta',
            ])->assertStatus(401);
        }

        // Un login correcto limpia el contador.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'cliente@example.com',
            'password' => 'secret-password',
        ])->assertOk();

        // Tras reiniciar, un nuevo intento fallido vuelve a responder 401
        // (no 429), porque el contador se reinició.
        $this->postJson('/api/v1/auth/login', [
            'email' => 'cliente@example.com',
            'password' => 'password-incorrecta',
        ])->assertStatus(401);
    }
}
