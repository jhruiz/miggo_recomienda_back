<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Pruebas de funcionalidad del endpoint de registro
 * (POST /api/v1/auth/register) y la verificación de correo.
 *
 * Cubre los Requisitos 1.1 (validación), 1.2 (cuenta pendiente de
 * verificación + correo), 1.3 (email duplicado) y 2.1 (rol cliente).
 */
class RegisterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Los roles de Spatie son prerrequisito del registro.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['cliente', 'prestador'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
    }

    /**
     * @return array<string, string>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ], $overrides);
    }

    public function test_registro_exitoso_crea_usuario_pendiente_de_verificacion(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/auth/register', $this->validPayload());

        $response->assertCreated()
            ->assertJsonPath('data.user.email', 'ada@example.com')
            ->assertJsonPath('data.user.email_verified', false)
            ->assertJsonStructure(['data' => ['user' => ['id', 'name', 'email', 'roles']], 'message']);

        $user = User::where('email', 'ada@example.com')->first();

        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at, 'La cuenta debe quedar pendiente de verificación.');
        $this->assertFalse($user->hasVerifiedEmail());
    }

    public function test_registro_asigna_rol_cliente_automaticamente(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', $this->validPayload())
            ->assertCreated()
            ->assertJsonPath('data.user.roles', ['cliente']);

        $user = User::where('email', 'ada@example.com')->firstOrFail();

        $this->assertTrue($user->hasRole('cliente'));
        $this->assertFalse($user->hasRole('prestador'));
    }

    public function test_registro_dispara_correo_de_verificacion(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', $this->validPayload())
            ->assertCreated();

        $user = User::where('email', 'ada@example.com')->firstOrFail();

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_registro_dispara_evento_registered(): void
    {
        Event::fake();

        $this->postJson('/api/v1/auth/register', $this->validPayload())
            ->assertCreated();

        Event::assertDispatched(Registered::class);
    }

    public function test_registro_con_email_duplicado_retorna_422(): void
    {
        Notification::fake();

        User::factory()->create(['email' => 'ada@example.com']);

        $response = $this->postJson('/api/v1/auth/register', $this->validPayload());

        $response->assertStatus(422)
            ->assertJsonValidationErrorFor('email')
            ->assertJsonPath('errors.email.0', 'El correo electrónico ya está en uso.');

        // No se debe crear un segundo usuario con ese correo.
        $this->assertSame(1, User::where('email', 'ada@example.com')->count());
    }

    public function test_registro_con_nombre_mayor_a_100_caracteres_retorna_422(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->validPayload([
            'name' => str_repeat('a', 101),
        ]));

        $response->assertStatus(422)->assertJsonValidationErrorFor('name');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registro_con_password_menor_a_8_caracteres_retorna_422(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->validPayload([
            'password' => 'short',
            'password_confirmation' => 'short',
        ]));

        $response->assertStatus(422)->assertJsonValidationErrorFor('password');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registro_con_password_mayor_a_72_caracteres_retorna_422(): void
    {
        $long = str_repeat('a', 73);

        $response = $this->postJson('/api/v1/auth/register', $this->validPayload([
            'password' => $long,
            'password_confirmation' => $long,
        ]));

        $response->assertStatus(422)->assertJsonValidationErrorFor('password');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registro_sin_confirmacion_de_password_retorna_422(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'secret-password',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrorFor('password');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registro_con_email_invalido_retorna_422(): void
    {
        $response = $this->postJson('/api/v1/auth/register', $this->validPayload([
            'email' => 'no-es-un-email',
        ]));

        $response->assertStatus(422)->assertJsonValidationErrorFor('email');
        $this->assertDatabaseCount('users', 0);
    }
}
