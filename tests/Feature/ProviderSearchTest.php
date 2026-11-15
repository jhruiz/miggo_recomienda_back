<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Department;
use App\Models\ProviderProfile;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Feature tests para la tarea 5.7: listado y búsqueda pública de prestadores
 * con filtros (GET /api/v1/providers).
 *
 * Cubre los Requisitos 13.1, 13.2, 13.3, 13.4, 13.5 y 13.6.
 */
class ProviderSearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Crea un prestador con perfil activo y los campos cacheados indicados.
     *
     * @param  array<string, mixed>  $profileAttributes
     * @param  list<ServiceCategory>  $categories
     */
    private function makeProvider(
        string $name,
        array $profileAttributes = [],
        array $categories = [],
    ): ProviderProfile {
        $user = User::factory()->create(['name' => $name]);

        $profile = ProviderProfile::create(array_merge([
            'user_id' => $user->id,
            'is_active' => true,
            'completed_jobs_count' => 0,
        ], $profileAttributes));

        if ($categories !== []) {
            $profile->categories()->sync(collect($categories)->pluck('id')->all());
        }

        return $profile;
    }

    private function makeCategory(string $name): ServiceCategory
    {
        return ServiceCategory::create([
            'name' => $name,
            'slug' => Str::slug($name),
        ]);
    }

    // --- Requisito 13.1 / 13.3: listado, orden y paginación ----------------

    /**
     * Requisito 13.1/13.3: el listado retorna solo perfiles activos, paginado
     * de 20 por defecto, con meta.total y meta.last_page.
     */
    public function test_listado_publico_pagina_de_20_con_meta(): void
    {
        // 25 prestadores activos => 2 páginas de 20.
        for ($i = 0; $i < 25; $i++) {
            $this->makeProvider("Prestador {$i}", ['compliance_index' => $i, 'completed_jobs_count' => 5]);
        }

        // Un prestador inactivo que NO debe aparecer.
        $this->makeProvider('Inactivo', ['is_active' => false]);

        $response = $this->getJson('/api/v1/providers');

        $response->assertOk()
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonCount(20, 'data');
    }

    /**
     * Requisito 13.1: el listado se ordena por Índice_Cumplimiento descendente;
     * los perfiles con datos suficientes (>=3 trabajos) aparecen antes.
     */
    public function test_listado_ordena_por_indice_cumplimiento_descendente(): void
    {
        $this->makeProvider('Bajo', ['compliance_index' => 40, 'completed_jobs_count' => 5]);
        $this->makeProvider('Alto', ['compliance_index' => 95, 'completed_jobs_count' => 5]);
        $this->makeProvider('Medio', ['compliance_index' => 70, 'completed_jobs_count' => 5]);

        $response = $this->getJson('/api/v1/providers');

        $response->assertOk();

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertSame(['Alto', 'Medio', 'Bajo'], $names);
    }

    /**
     * Requisito 9.4/13.1: un prestador con <3 trabajos completados muestra
     * "Sin datos suficientes" en el Índice_Cumplimiento y aparece al final.
     */
    public function test_prestador_sin_datos_suficientes_muestra_etiqueta_y_queda_al_final(): void
    {
        $this->makeProvider('ConDatos', ['compliance_index' => 50, 'completed_jobs_count' => 5]);
        $this->makeProvider('SinDatos', ['compliance_index' => null, 'completed_jobs_count' => 1]);

        $response = $this->getJson('/api/v1/providers');

        $response->assertOk();

        $data = $response->json('data');
        $this->assertSame('ConDatos', $data[0]['name']);
        $this->assertSame('SinDatos', $data[1]['name']);
        $this->assertSame('Sin datos suficientes', $data[1]['compliance_index']);
    }

    // --- Requisito 13.2: filtros ------------------------------------------

    public function test_filtra_por_categoria(): void
    {
        $plomeria = $this->makeCategory('Plomería');
        $electricidad = $this->makeCategory('Electricidad');

        $this->makeProvider('Plomero', ['completed_jobs_count' => 5], [$plomeria]);
        $this->makeProvider('Electricista', ['completed_jobs_count' => 5], [$electricidad]);

        $response = $this->getJson('/api/v1/providers?category=plomeria');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Plomero');
    }

    public function test_filtra_por_departamento_y_ciudad_del_catalogo(): void
    {
        // Dos ciudades en departamentos distintos del Catálogo_Ubicación.
        $cityA = City::factory()->create();
        $cityB = City::factory()->create();

        $this->makeProvider('EnCiudadA', ['city_id' => $cityA->id]);
        $this->makeProvider('EnCiudadB', ['city_id' => $cityB->id]);

        // Filtro por Departamento: incluye los prestadores cuya Ciudad
        // pertenece a ese Departamento (Requisitos 13.8, 13.9).
        $this->getJson('/api/v1/providers?department_id='.$cityA->department_id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'EnCiudadA');

        // Filtro por Ciudad: acota a esa Ciudad concreta (Requisito 13.8).
        $this->getJson('/api/v1/providers?city_id='.$cityB->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'EnCiudadB');
    }

    public function test_filtra_por_rango_de_calificacion(): void
    {
        $this->makeProvider('Buena', ['average_rating' => 4.5, 'completed_jobs_count' => 5]);
        $this->makeProvider('Regular', ['average_rating' => 2.5, 'completed_jobs_count' => 5]);
        $this->makeProvider('SinCalif', ['average_rating' => null, 'completed_jobs_count' => 5]);

        $response = $this->getJson('/api/v1/providers?rating_min=4.0&rating_max=5.0');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Buena');
    }

    // --- Requisito 13.4: búsqueda por texto -------------------------------

    public function test_busqueda_por_nombre_parcial_case_insensitive(): void
    {
        $this->makeProvider('Juan Pérez');
        $this->makeProvider('María López');

        $response = $this->getJson('/api/v1/providers?search=pér');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Juan Pérez');
    }

    public function test_busqueda_por_descripcion_profesional(): void
    {
        $this->makeProvider('Ana', ['professional_description' => 'Especialista en reparación de aires acondicionados']);
        $this->makeProvider('Luis', ['professional_description' => 'Diseñador gráfico freelance']);

        $response = $this->getJson('/api/v1/providers?search=acondicionado');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Ana');
    }

    // --- Requisito 13.5: validación de longitud del término ----------------

    public function test_termino_de_busqueda_demasiado_corto_retorna_422(): void
    {
        $this->makeProvider('Alguien');

        $this->getJson('/api/v1/providers?search=a')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['search']);
    }

    public function test_termino_de_busqueda_demasiado_largo_retorna_422(): void
    {
        $longTerm = str_repeat('a', 101);

        $this->getJson('/api/v1/providers?search='.$longTerm)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['search']);
    }

    public function test_rango_de_calificacion_fuera_de_limite_retorna_422(): void
    {
        $this->getJson('/api/v1/providers?rating_min=0.5')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rating_min']);
    }

    // --- Requisito 13.6: sin resultados => lista vacía + mensaje (no error) --

    public function test_sin_resultados_retorna_lista_vacia_con_mensaje_y_200(): void
    {
        $this->makeProvider('Único', ['professional_description' => 'Plomería']);

        $response = $this->getJson('/api/v1/providers?search=inexistente');

        $response->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);

        $this->assertStringContainsString(
            'No se encontraron prestadores',
            $response->json('message'),
        );
    }

    // --- Público: no requiere autenticación --------------------------------

    public function test_endpoint_es_publico(): void
    {
        $this->makeProvider('Público');

        $this->getJson('/api/v1/providers')->assertOk();
    }
}
