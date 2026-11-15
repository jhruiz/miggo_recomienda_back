<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Department;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Services\ProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests para la tarea 4.1: asignación de rol cliente al registrar,
 * activación del rol prestador y actualización de perfil propio.
 *
 * Cubre los Requisitos 2.1, 2.2, 2.3 y 2.5.
 */
class ProfileRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase parte de una BD vacía: sembramos los roles Spatie
        // `cliente` y `prestador` que el dominio asume existentes.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['cliente', 'prestador'] as $roleName) {
            Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
            ]);
        }
    }

    // --- Requisito 2.1: rol cliente al registrar ---------------------------

    /**
     * Requisito 2.1: al completar el registro, el usuario recibe
     * automáticamente el rol `cliente` (lo asigna AuthService::register(),
     * aquí solo se verifica el comportamiento de extremo a extremo).
     */
    public function test_registro_asigna_automaticamente_rol_cliente(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Nuevo Usuario',
            'email' => 'nuevo@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ]);

        $response->assertCreated();

        $user = User::where('email', 'nuevo@example.com')->firstOrFail();

        $this->assertTrue($user->hasRole('cliente'));
        $this->assertFalse($user->hasRole('prestador'));
    }

    // --- Requisito 2.2: activar rol prestador ------------------------------

    /**
     * Requisito 2.2: activar el rol de prestador crea el provider_profile
     * (is_active = true) y asigna el rol `prestador` conservando `cliente`.
     */
    public function test_activar_rol_prestador_crea_perfil_y_conserva_cliente(): void
    {
        $user = User::factory()->create();
        $user->assignRole('cliente');

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/profile/provider/activate');

        $response->assertOk()
            ->assertJsonPath('data.provider_profile.user_id', $user->id)
            ->assertJsonPath('data.provider_profile.is_active', true);

        // El perfil de prestador se creó activo.
        $this->assertDatabaseHas('provider_profiles', [
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        // Ambos roles coexisten: prestador añadido, cliente conservado.
        $user->refresh();
        $this->assertTrue($user->hasRole('prestador'));
        $this->assertTrue($user->hasRole('cliente'));
    }

    /**
     * Requisito 2.2 (idempotencia): activar el rol de prestador dos veces no
     * duplica el provider_profile ni la asignación de rol.
     */
    public function test_activar_rol_prestador_es_idempotente(): void
    {
        $user = User::factory()->create();
        $user->assignRole('cliente');

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/profile/provider/activate')->assertOk();
        $this->postJson('/api/v1/profile/provider/activate')->assertOk();

        // Un solo perfil para el usuario.
        $this->assertDatabaseCount('provider_profiles', 1);
        $this->assertSame(
            1,
            ProviderProfile::where('user_id', $user->id)->count(),
        );

        // El rol prestador aparece una sola vez (sin duplicar la relación).
        $user->refresh();
        $this->assertSame(
            1,
            $user->roles->where('name', 'prestador')->count(),
        );
        $this->assertTrue($user->hasRole('cliente'));
    }

    // --- Requisito 2.3: transacción con rollback ---------------------------

    /**
     * Requisito 2.3: si la activación del rol prestador falla a mitad de
     * camino, la transacción hace rollback y el usuario NO queda con el rol
     * asignado parcialmente ni con un perfil huérfano.
     *
     * Se fuerza el fallo haciendo que assignRole() lance una excepción DESPUÉS
     * de que firstOrCreate() ya haya intentado crear el perfil dentro de la
     * misma transacción; al propagarse, DB::transaction revierte todo.
     */
    public function test_fallo_en_activacion_hace_rollback_y_no_asigna_rol_parcial(): void
    {
        $user = User::factory()->create();
        $user->assignRole('cliente');

        // Mock parcial del usuario: assignRole('prestador') lanza una excepción
        // simulando un fallo del sistema a mitad de la activación.
        $failingUser = Mockery::mock($user)->makePartial();
        $failingUser->shouldReceive('assignRole')
            ->with('prestador')
            ->andThrow(new RuntimeException('Fallo simulado del sistema'));

        $service = app(ProfileService::class);

        try {
            $service->activateProviderRole($failingUser);
            $this->fail('Se esperaba que activateProviderRole propagara la excepción.');
        } catch (RuntimeException $e) {
            $this->assertSame('Fallo simulado del sistema', $e->getMessage());
        }

        // Rollback: no se persistió ningún provider_profile para el usuario.
        $this->assertDatabaseMissing('provider_profiles', [
            'user_id' => $user->id,
        ]);

        // El usuario conserva solo el rol cliente; no quedó prestador parcial.
        $user->refresh();
        $this->assertTrue($user->hasRole('cliente'));
        $this->assertFalse($user->hasRole('prestador'));
    }

    // --- Requisito 2.5: actualización de perfil propio ---------------------

    /**
     * Requisito 2.5: el usuario puede actualizar sus datos de identidad
     * compartidos (nombre), que son únicos para la cuenta.
     */
    public function test_actualizar_perfil_propio_cambia_datos_de_identidad(): void
    {
        $user = User::factory()->create([
            'name' => 'Nombre Viejo',
            'email' => 'viejo@example.com',
        ]);
        $user->assignRole('cliente');

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/profile', [
            'name' => 'Nombre Nuevo',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.name', 'Nombre Nuevo');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Nombre Nuevo',
            'email' => 'viejo@example.com',
        ]);
    }

    /**
     * Requisito 2.5: la actualización de perfil requiere autenticación.
     */
    public function test_actualizar_perfil_sin_autenticacion_retorna_401(): void
    {
        $this->putJson('/api/v1/profile', ['name' => 'X'])->assertStatus(401);
    }

    /**
     * Actualizar el perfil de prestador tras activarlo cambia descripción y
     * ubicación.
     */
    public function test_actualizar_perfil_de_prestador_tras_activar(): void
    {
        $user = User::factory()->create();
        $user->assignRole('cliente');

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/profile/provider/activate')->assertOk();

        $city = City::factory()->create();

        $response = $this->putJson('/api/v1/profile/provider', [
            'professional_description' => 'Electricista certificado con 10 años de experiencia.',
            'city_id' => $city->id,
            'location' => 'Av. Principal 45',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.provider_profile.city_id', $city->id)
            ->assertJsonPath('data.provider_profile.city.id', $city->id)
            ->assertJsonPath('data.provider_profile.location', 'Av. Principal 45');

        $this->assertDatabaseHas('provider_profiles', [
            'user_id' => $user->id,
            'professional_description' => 'Electricista certificado con 10 años de experiencia.',
            'city_id' => $city->id,
            'location' => 'Av. Principal 45',
        ]);
    }

    /**
     * Requisito 3.12: al actualizar el perfil de prestador con una Ciudad que
     * no existe en el Catálogo_Ubicación, la operación se rechaza con 422 sin
     * persistir el cambio.
     */
    public function test_actualizar_perfil_de_prestador_rechaza_city_id_inexistente(): void
    {
        $user = User::factory()->create();
        $user->assignRole('cliente');

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/profile/provider/activate')->assertOk();

        $this->putJson('/api/v1/profile/provider', [
            'city_id' => 999999,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['city_id']);
    }

    /**
     * Requisito 3.12: al actualizar con una Ciudad que no pertenece al
     * Departamento indicado, la operación se rechaza con 422 sin persistir.
     */
    public function test_actualizar_perfil_de_prestador_rechaza_ciudad_de_otro_departamento(): void
    {
        $user = User::factory()->create();
        $user->assignRole('cliente');

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/profile/provider/activate')->assertOk();

        $city = City::factory()->create();
        $otherDepartment = Department::factory()->create();

        $this->putJson('/api/v1/profile/provider', [
            'city_id' => $city->id,
            'department_id' => $otherDepartment->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['city_id']);
    }

    /**
     * Intentar actualizar el perfil de prestador sin tener el rol `prestador`
     * activo retorna 403 (Requisito 2.6): el middleware `role.provider`
     * rechaza la acci�n antes de llegar al controlador, indicando que debe
     * activarse el rol de Prestador.
     */
    public function test_actualizar_perfil_de_prestador_sin_rol_retorna_403(): void
    {
        $user = User::factory()->create();
        $user->assignRole('cliente');

        Sanctum::actingAs($user);

        $this->putJson('/api/v1/profile/provider', [
            'location' => 'Santiago',
        ])->assertStatus(403);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
