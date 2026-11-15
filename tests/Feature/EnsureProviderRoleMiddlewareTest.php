<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests para el middleware de autorización por rol activo de
 * Prestador (tarea 4.3), alias `role.provider`.
 *
 * Cubre el Requisito 2.6: si un Usuario intenta una acción de Prestador
 * sin tener el rol de Prestador activo, el sistema debe retornar un error
 * de autorización (403) con un mensaje indicando que debe activar el rol
 * de Prestador, preservando el estado previo de la sesión.
 *
 * Las rutas de prueba se registran dentro del propio test (en setUp) para
 * no depender de las rutas definidas en routes/api.php (que otras tareas
 * construyen en paralelo).
 */
class EnsureProviderRoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Siembra los roles de Spatie (cliente y prestador) con el guard
        // por defecto, replicando RoleSeeder.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['cliente', 'prestador'] as $roleName) {
            Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
            ]);
        }

        // Ruta de prueba protegida: requiere autenticación por Sanctum y el
        // rol activo de Prestador. Vive solo durante el test, no toca
        // routes/api.php.
        Route::middleware(['auth:sanctum', 'role.provider'])
            ->get('/_test/provider-only', fn () => response()->json(['ok' => true]));
    }

    /**
     * Requisito 2.6: un usuario con rol `prestador` accede a la ruta
     * protegida (el middleware autoriza).
     */
    public function test_usuario_con_rol_prestador_accede_a_ruta_protegida(): void
    {
        $user = User::factory()->create();
        $user->assignRole('prestador');

        $this->actingAs($user, 'sanctum')
            ->getJson('/_test/provider-only')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    /**
     * Requisito 2.6: un usuario con solo el rol `cliente` recibe 403 con el
     * envelope estándar y el mensaje indicando que debe activar el rol de
     * Prestador.
     */
    public function test_usuario_solo_cliente_recibe_403_con_mensaje_de_activar_prestador(): void
    {
        $user = User::factory()->create();
        $user->assignRole('cliente');

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/_test/provider-only');

        $response->assertStatus(403)
            ->assertJsonStructure(['data', 'message'])
            ->assertJsonPath('data', null);

        $this->assertStringContainsStringIgnoringCase('Prestador', $response->json('message'));
        $this->assertStringContainsStringIgnoringCase('activar', $response->json('message'));
    }

    /**
     * Requisito 2.6: un usuario sin ningún rol también es rechazado con 403
     * (no tiene el rol de Prestador activo).
     */
    public function test_usuario_sin_roles_recibe_403(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/_test/provider-only')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Debes activar el rol de Prestador para realizar esta acción.');
    }

    /**
     * Un usuario no autenticado recibe 401 (la autenticación se evalúa antes
     * que la comprobación de rol).
     */
    public function test_usuario_no_autenticado_recibe_401(): void
    {
        $this->getJson('/_test/provider-only')
            ->assertStatus(401);
    }
}
