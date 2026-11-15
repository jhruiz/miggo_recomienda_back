<?php

namespace Tests\Feature;

use App\Contracts\QuoteRequestNotifier;
use App\Models\City;
use App\Models\QuoteRequest;
use App\Models\QuoteRequestApplication;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests para la tarea 7.1: creación, edición y cierre de
 * Solicitudes_Cotización (Requisitos 4.1, 4.2, 4.6, 4.7).
 *
 * Cubre:
 *  - 4.1 validación de campos (título ≤100, descripción ≤2000, categoría,
 *    ubicación, presupuesto opcional) y rechazo 422 sin persistir.
 *  - 4.2 estado inicial "abierta".
 *  - 4.6 edición solo en estado "abierta" (409 en otro caso) y notificación a
 *    postulantes.
 *  - 4.7 cierre solo en estado "abierta" (409 en otro caso) y notificación a
 *    postulantes.
 */
class QuoteRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['cliente', 'prestador'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
    }

    private function category(string $slug = 'plomeria'): ServiceCategory
    {
        return ServiceCategory::create(['name' => ucfirst($slug), 'slug' => $slug]);
    }

    /**
     * Crea una Ciudad del Catálogo_Ubicación (con su Departamento y País)
     * para usar como ubicación canónica en las pruebas de escritura.
     */
    private function city(): City
    {
        return City::factory()->create();
    }

    private function actingAsClient(): User
    {
        $user = User::factory()->create();
        $user->assignRole('cliente');
        Sanctum::actingAs($user);

        return $user;
    }

    private function validPayload(ServiceCategory $category, array $overrides = []): array
    {
        $payload = array_merge([
            'title' => 'Reparación de tubería',
            'description' => 'Necesito reparar una fuga en la cocina.',
            'service_category_id' => $category->id,
            // Ciudad del Catálogo_Ubicación: ubicación canónica (requerida).
            'city_id' => $this->city()->id,
            // Dirección_Referencia opcional (texto libre).
            'location' => 'Calle 123, apto 4B',
            'estimated_budget' => 250.50,
        ], $overrides);

        return $payload;
    }

    // --- Requisito 4.1 / 4.2: creación -------------------------------------

    public function test_cliente_crea_solicitud_en_estado_abierta(): void
    {
        $user = $this->actingAsClient();
        $category = $this->category();

        $response = $this->postJson('/api/v1/quote-requests', $this->validPayload($category));

        $response->assertCreated()
            ->assertJsonPath('data.quote_request.status', QuoteRequest::STATUS_ABIERTA)
            ->assertJsonPath('data.quote_request.title', 'Reparación de tubería')
            ->assertJsonPath('data.quote_request.estimated_budget', 250.5);

        $this->assertDatabaseHas('quote_requests', [
            'client_user_id' => $user->id,
            'title' => 'Reparación de tubería',
            'status' => QuoteRequest::STATUS_ABIERTA,
        ]);
    }

    public function test_presupuesto_es_opcional(): void
    {
        $this->actingAsClient();
        $category = $this->category();

        $this->postJson('/api/v1/quote-requests', $this->validPayload($category, ['estimated_budget' => null]))
            ->assertCreated()
            ->assertJsonPath('data.quote_request.estimated_budget', null);
    }

    public function test_crear_sin_autenticacion_retorna_401(): void
    {
        $category = $this->category();

        $this->postJson('/api/v1/quote-requests', $this->validPayload($category))
            ->assertStatus(401);
    }

    // --- Requisitos 4.13 / 4.14: imágenes de referencia al crear ------------

    public function test_crear_con_imagenes_validas_las_adjunta(): void
    {
        $this->actingAsClient();
        $payload = $this->validPayload($this->category(), [
            'reference_images' => [
                \Illuminate\Http\UploadedFile::fake()->image('a.jpg', 40, 40),
                \Illuminate\Http\UploadedFile::fake()->image('b.png', 40, 40),
            ],
        ]);

        $this->post('/api/v1/quote-requests', $payload, ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonCount(2, 'data.quote_request.reference_images');

        $request = QuoteRequest::firstOrFail();
        $this->assertCount(2, $request->getMedia(QuoteRequest::REFERENCE_IMAGES_MEDIA_COLLECTION));
        $request->clearMediaCollection(QuoteRequest::REFERENCE_IMAGES_MEDIA_COLLECTION);
    }

    public function test_crear_rechaza_imagen_con_tipo_no_permitido_sin_persistir(): void
    {
        $this->actingAsClient();
        $payload = $this->validPayload($this->category(), [
            'reference_images' => [
                \Illuminate\Http\UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
            ],
        ]);

        $this->post('/api/v1/quote-requests', $payload, ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reference_images.0']);

        $this->assertDatabaseCount('quote_requests', 0);
    }

    public function test_crear_rechaza_imagen_mayor_a_10mb_sin_persistir(): void
    {
        $this->actingAsClient();
        $payload = $this->validPayload($this->category(), [
            'reference_images' => [
                \Illuminate\Http\UploadedFile::fake()->image('big.jpg')->size(10241),
            ],
        ]);

        $this->post('/api/v1/quote-requests', $payload, ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reference_images.0']);

        $this->assertDatabaseCount('quote_requests', 0);
    }

    public function test_crear_rechaza_mas_de_5_imagenes_sin_persistir(): void
    {
        $this->actingAsClient();
        $images = array_map(
            fn ($i) => \Illuminate\Http\UploadedFile::fake()->image("i{$i}.jpg", 20, 20),
            range(1, 6),
        );
        $payload = $this->validPayload($this->category(), ['reference_images' => $images]);

        $this->post('/api/v1/quote-requests', $payload, ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reference_images']);

        $this->assertDatabaseCount('quote_requests', 0);
    }

    // --- Requisito 4.1: validación (422 sin persistir) ---------------------

    public function test_rechaza_titulo_mayor_a_100(): void
    {
        $this->actingAsClient();
        $category = $this->category();

        $this->postJson('/api/v1/quote-requests', $this->validPayload($category, ['title' => str_repeat('a', 101)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title']);

        $this->assertDatabaseCount('quote_requests', 0);
    }

    public function test_rechaza_descripcion_mayor_a_2000(): void
    {
        $this->actingAsClient();
        $category = $this->category();

        $this->postJson('/api/v1/quote-requests', $this->validPayload($category, ['description' => str_repeat('a', 2001)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['description']);

        $this->assertDatabaseCount('quote_requests', 0);
    }

    public function test_rechaza_campos_requeridos_ausentes(): void
    {
        $this->actingAsClient();

        // La ubicación canónica es ahora la Ciudad (city_id), requerida;
        // `location` (Dirección_Referencia) es opcional (Requisitos 4.10, 4.11).
        $this->postJson('/api/v1/quote-requests', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'description', 'service_category_id', 'city_id'])
            ->assertJsonMissingValidationErrors(['location']);

        $this->assertDatabaseCount('quote_requests', 0);
    }

    // --- Requisitos 4.10 / 4.11 / 4.12: Ciudad y Dirección_Referencia ------

    public function test_crear_persiste_city_id_y_location_opcional(): void
    {
        $user = $this->actingAsClient();
        $category = $this->category();
        $city = $this->city();

        $response = $this->postJson('/api/v1/quote-requests', $this->validPayload($category, [
            'city_id' => $city->id,
            'location' => 'Referencia exacta',
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.quote_request.city_id', $city->id)
            ->assertJsonPath('data.quote_request.location', 'Referencia exacta')
            ->assertJsonPath('data.quote_request.city.id', $city->id);

        $this->assertDatabaseHas('quote_requests', [
            'client_user_id' => $user->id,
            'city_id' => $city->id,
            'location' => 'Referencia exacta',
        ]);
    }

    public function test_crear_sin_location_usa_null_como_direccion_referencia(): void
    {
        $this->actingAsClient();
        $category = $this->category();
        $city = $this->city();

        $payload = $this->validPayload($category, ['city_id' => $city->id]);
        unset($payload['location']);

        $this->postJson('/api/v1/quote-requests', $payload)
            ->assertCreated()
            ->assertJsonPath('data.quote_request.location', null)
            ->assertJsonPath('data.quote_request.city_id', $city->id);
    }

    public function test_rechaza_city_id_inexistente(): void
    {
        $this->actingAsClient();
        $category = $this->category();

        $this->postJson('/api/v1/quote-requests', $this->validPayload($category, ['city_id' => 999999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['city_id']);

        $this->assertDatabaseCount('quote_requests', 0);
    }

    public function test_rechaza_city_id_que_no_pertenece_al_departamento(): void
    {
        $this->actingAsClient();
        $category = $this->category();

        // Ciudad en un departamento, pero se envía el id de OTRO departamento.
        $city = $this->city();
        $otherDepartment = \App\Models\Department::factory()->create();

        $this->postJson('/api/v1/quote-requests', $this->validPayload($category, [
            'city_id' => $city->id,
            'department_id' => $otherDepartment->id,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['city_id']);

        $this->assertDatabaseCount('quote_requests', 0);
    }

    public function test_acepta_city_id_perteneciente_al_departamento(): void
    {
        $this->actingAsClient();
        $category = $this->category();

        $city = $this->city();

        $this->postJson('/api/v1/quote-requests', $this->validPayload($category, [
            'city_id' => $city->id,
            'department_id' => $city->department_id,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.quote_request.city_id', $city->id);
    }

    public function test_rechaza_categoria_inexistente(): void
    {
        $this->actingAsClient();
        $category = $this->category();

        $this->postJson('/api/v1/quote-requests', $this->validPayload($category, ['service_category_id' => 99999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['service_category_id']);
    }

    // --- Listado y detalle públicos ----------------------------------------

    public function test_listado_publico_sin_autenticacion(): void
    {
        $client = User::factory()->create();
        $category = $this->category();
        QuoteRequest::create([
            'client_user_id' => $client->id,
            'service_category_id' => $category->id,
            'title' => 'Pintura',
            'description' => 'Pintar sala',
            'location' => 'Cusco',
            'status' => QuoteRequest::STATUS_ABIERTA,
        ]);

        $this->getJson('/api/v1/quote-requests')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_detalle_publico_sin_autenticacion(): void
    {
        $client = User::factory()->create();
        $category = $this->category();
        $request = QuoteRequest::create([
            'client_user_id' => $client->id,
            'service_category_id' => $category->id,
            'title' => 'Pintura',
            'description' => 'Pintar sala',
            'location' => 'Cusco',
            'status' => QuoteRequest::STATUS_ABIERTA,
        ]);

        $this->getJson("/api/v1/quote-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.quote_request.id', $request->id);
    }

    // --- Requisito 4.15: imágenes de referencia en el detalle público -------

    public function test_detalle_publico_expone_imagenes_de_referencia(): void
    {
        $client = User::factory()->create();
        $request = QuoteRequest::create([
            'client_user_id' => $client->id,
            'service_category_id' => $this->category()->id,
            'title' => 'Pintura',
            'description' => 'Pintar sala',
            'location' => 'Cusco',
            'status' => QuoteRequest::STATUS_ABIERTA,
        ]);

        $media = $request->addMedia(\Illuminate\Http\UploadedFile::fake()->image('a.jpg', 50, 50))
            ->toMediaCollection(QuoteRequest::REFERENCE_IMAGES_MEDIA_COLLECTION);

        // Sin autenticación: cualquier visitante/Prestador puede verlas.
        $this->getJson("/api/v1/quote-requests/{$request->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.quote_request.reference_images')
            ->assertJsonPath('data.quote_request.reference_images.0.id', $media->id)
            ->assertJsonStructure(['data' => ['quote_request' => ['reference_images' => [['id', 'url', 'thumb_url']]]]]);

        $media->delete();
    }

    public function test_detalle_sin_imagenes_devuelve_lista_vacia(): void
    {
        $request = QuoteRequest::create([
            'client_user_id' => User::factory()->create()->id,
            'service_category_id' => $this->category()->id,
            'title' => 'Pintura',
            'description' => 'Pintar sala',
            'location' => 'Cusco',
            'status' => QuoteRequest::STATUS_ABIERTA,
        ]);

        $this->getJson("/api/v1/quote-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.quote_request.reference_images', []);
    }

    // --- Requisito 4.16: alta/baja de imágenes de referencia ----------------

    private function ownedRequest(User $owner, string $status = QuoteRequest::STATUS_ABIERTA): QuoteRequest
    {
        return QuoteRequest::create([
            'client_user_id' => $owner->id,
            'service_category_id' => $this->category()->id,
            'title' => 'Pintura',
            'description' => 'Pintar sala',
            'location' => 'Cusco',
            'status' => $status,
        ]);
    }

    private function fakeImages(int $n): array
    {
        return array_map(
            fn ($i) => \Illuminate\Http\UploadedFile::fake()->image("img{$i}.jpg", 40, 40),
            range(1, $n),
        );
    }

    public function test_dueno_agrega_y_elimina_imagenes_en_solicitud_abierta(): void
    {
        $owner = $this->actingAsClient();
        $request = $this->ownedRequest($owner);

        $response = $this->post(
            "/api/v1/quote-requests/{$request->id}/reference-images",
            ['reference_images' => $this->fakeImages(2)],
            ['Accept' => 'application/json'],
        );
        $response->assertCreated()->assertJsonCount(2, 'data.quote_request.reference_images');

        $mediaId = $response->json('data.quote_request.reference_images.0.id');

        $this->deleteJson("/api/v1/quote-requests/{$request->id}/reference-images/{$mediaId}")
            ->assertOk()
            ->assertJsonCount(1, 'data.quote_request.reference_images');

        $request->refresh()->clearMediaCollection(QuoteRequest::REFERENCE_IMAGES_MEDIA_COLLECTION);
    }

    public function test_agregar_imagenes_sobre_el_tope_de_5_devuelve_422_sin_persistir(): void
    {
        $owner = $this->actingAsClient();
        $request = $this->ownedRequest($owner);

        $this->post("/api/v1/quote-requests/{$request->id}/reference-images",
            ['reference_images' => $this->fakeImages(4)], ['Accept' => 'application/json'])->assertCreated();

        $this->post("/api/v1/quote-requests/{$request->id}/reference-images",
            ['reference_images' => $this->fakeImages(2)], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertCount(4, $request->refresh()->getMedia(QuoteRequest::REFERENCE_IMAGES_MEDIA_COLLECTION));

        $request->clearMediaCollection(QuoteRequest::REFERENCE_IMAGES_MEDIA_COLLECTION);
    }

    public function test_no_dueno_recibe_403_al_agregar_o_eliminar_imagenes(): void
    {
        $request = $this->ownedRequest(User::factory()->create());
        $media = $request->addMedia($this->fakeImages(1)[0])
            ->toMediaCollection(QuoteRequest::REFERENCE_IMAGES_MEDIA_COLLECTION);
        $this->actingAsClient();

        $this->post("/api/v1/quote-requests/{$request->id}/reference-images",
            ['reference_images' => $this->fakeImages(1)], ['Accept' => 'application/json'])->assertForbidden();
        $this->deleteJson("/api/v1/quote-requests/{$request->id}/reference-images/{$media->id}")->assertForbidden();

        $media->delete();
    }

    public function test_solicitud_no_abierta_devuelve_409_al_agregar_o_eliminar_imagenes(): void
    {
        $owner = $this->actingAsClient();
        $request = $this->ownedRequest($owner, QuoteRequest::STATUS_CERRADA);
        $media = $request->addMedia($this->fakeImages(1)[0])
            ->toMediaCollection(QuoteRequest::REFERENCE_IMAGES_MEDIA_COLLECTION);

        $this->post("/api/v1/quote-requests/{$request->id}/reference-images",
            ['reference_images' => $this->fakeImages(1)], ['Accept' => 'application/json'])->assertStatus(409);
        $this->deleteJson("/api/v1/quote-requests/{$request->id}/reference-images/{$media->id}")->assertStatus(409);

        $media->delete();
    }

    // --- Requisito 4.6: edición --------------------------------------------

    public function test_cliente_dueno_edita_solicitud_abierta(): void
    {
        $user = $this->actingAsClient();
        $category = $this->category();
        $request = QuoteRequest::create([
            'client_user_id' => $user->id,
            'service_category_id' => $category->id,
            'title' => 'Original',
            'description' => 'Desc original',
            'location' => 'Lima',
            'status' => QuoteRequest::STATUS_ABIERTA,
        ]);

        $this->putJson("/api/v1/quote-requests/{$request->id}", ['title' => 'Editado'])
            ->assertOk()
            ->assertJsonPath('data.quote_request.title', 'Editado');

        $this->assertDatabaseHas('quote_requests', ['id' => $request->id, 'title' => 'Editado']);
    }

    public function test_editar_solicitud_no_abierta_retorna_409(): void
    {
        $user = $this->actingAsClient();
        $category = $this->category();
        $request = QuoteRequest::create([
            'client_user_id' => $user->id,
            'service_category_id' => $category->id,
            'title' => 'Original',
            'description' => 'Desc',
            'location' => 'Lima',
            'status' => QuoteRequest::STATUS_EN_PROCESO,
        ]);

        $this->putJson("/api/v1/quote-requests/{$request->id}", ['title' => 'Editado'])
            ->assertStatus(409);

        $this->assertDatabaseHas('quote_requests', ['id' => $request->id, 'title' => 'Original']);
    }

    public function test_no_dueno_no_puede_editar(): void
    {
        $owner = User::factory()->create();
        $category = $this->category();
        $request = QuoteRequest::create([
            'client_user_id' => $owner->id,
            'service_category_id' => $category->id,
            'title' => 'Original',
            'description' => 'Desc',
            'location' => 'Lima',
            'status' => QuoteRequest::STATUS_ABIERTA,
        ]);

        $this->actingAsClient(); // otro usuario

        $this->putJson("/api/v1/quote-requests/{$request->id}", ['title' => 'Hackeo'])
            ->assertStatus(403);

        $this->assertDatabaseHas('quote_requests', ['id' => $request->id, 'title' => 'Original']);
    }

    public function test_editar_notifica_a_postulantes(): void
    {
        $user = $this->actingAsClient();
        $category = $this->category();
        $request = QuoteRequest::create([
            'client_user_id' => $user->id,
            'service_category_id' => $category->id,
            'title' => 'Original',
            'description' => 'Desc',
            'location' => 'Lima',
            'status' => QuoteRequest::STATUS_ABIERTA,
        ]);

        $notifier = Mockery::mock(QuoteRequestNotifier::class);
        $notifier->shouldReceive('notifyApplicantsOfEdit')->once();
        $notifier->shouldReceive('notifyApplicantsOfClosure')->never();
        $this->app->instance(QuoteRequestNotifier::class, $notifier);

        $this->putJson("/api/v1/quote-requests/{$request->id}", ['title' => 'Editado'])
            ->assertOk();
    }

    // --- Requisito 4.7: cierre ---------------------------------------------

    public function test_cliente_dueno_cierra_solicitud_abierta(): void
    {
        $user = $this->actingAsClient();
        $category = $this->category();
        $request = QuoteRequest::create([
            'client_user_id' => $user->id,
            'service_category_id' => $category->id,
            'title' => 'Original',
            'description' => 'Desc',
            'location' => 'Lima',
            'status' => QuoteRequest::STATUS_ABIERTA,
        ]);

        $this->postJson("/api/v1/quote-requests/{$request->id}/close")
            ->assertOk()
            ->assertJsonPath('data.quote_request.status', QuoteRequest::STATUS_CERRADA);

        $this->assertDatabaseHas('quote_requests', [
            'id' => $request->id,
            'status' => QuoteRequest::STATUS_CERRADA,
        ]);
    }

    public function test_cerrar_solicitud_no_abierta_retorna_409(): void
    {
        $user = $this->actingAsClient();
        $category = $this->category();
        $request = QuoteRequest::create([
            'client_user_id' => $user->id,
            'service_category_id' => $category->id,
            'title' => 'Original',
            'description' => 'Desc',
            'location' => 'Lima',
            'status' => QuoteRequest::STATUS_CERRADA,
        ]);

        $this->postJson("/api/v1/quote-requests/{$request->id}/close")
            ->assertStatus(409);
    }

    public function test_cerrar_notifica_a_postulantes(): void
    {
        $user = $this->actingAsClient();
        $category = $this->category();
        $request = QuoteRequest::create([
            'client_user_id' => $user->id,
            'service_category_id' => $category->id,
            'title' => 'Original',
            'description' => 'Desc',
            'location' => 'Lima',
            'status' => QuoteRequest::STATUS_ABIERTA,
        ]);

        $notifier = Mockery::mock(QuoteRequestNotifier::class);
        $notifier->shouldReceive('notifyApplicantsOfClosure')->once();
        $notifier->shouldReceive('notifyApplicantsOfEdit')->never();
        $this->app->instance(QuoteRequestNotifier::class, $notifier);

        $this->postJson("/api/v1/quote-requests/{$request->id}/close")
            ->assertOk();
    }

    public function test_no_dueno_no_puede_cerrar(): void
    {
        $owner = User::factory()->create();
        $category = $this->category();
        $request = QuoteRequest::create([
            'client_user_id' => $owner->id,
            'service_category_id' => $category->id,
            'title' => 'Original',
            'description' => 'Desc',
            'location' => 'Lima',
            'status' => QuoteRequest::STATUS_ABIERTA,
        ]);

        $this->actingAsClient();

        $this->postJson("/api/v1/quote-requests/{$request->id}/close")
            ->assertStatus(403);
    }

    // --- Requisito 4.5: selección del Prestador ganador (tarea 7.5) --------

    /**
     * Crea una Solicitud "abierta" del cliente dado y registra la postulación
     * de un Prestador. Devuelve [Solicitud, Prestador].
     *
     * @return array{0: QuoteRequest, 1: User}
     */
    private function openRequestWithApplicant(User $owner, ServiceCategory $category): array
    {
        $request = QuoteRequest::create([
            'client_user_id' => $owner->id,
            'service_category_id' => $category->id,
            'title' => 'Instalación eléctrica',
            'description' => 'Instalar tomas en la cocina',
            'location' => 'Lima',
            'status' => QuoteRequest::STATUS_ABIERTA,
        ]);

        $provider = User::factory()->create();
        $provider->assignRole('prestador');

        QuoteRequestApplication::create([
            'quote_request_id' => $request->id,
            'provider_user_id' => $provider->id,
        ]);

        return [$request, $provider];
    }

    public function test_cliente_dueno_selecciona_prestador_pone_en_proceso(): void
    {
        $owner = $this->actingAsClient();
        $category = $this->category();
        [$request, $provider] = $this->openRequestWithApplicant($owner, $category);

        $this->postJson("/api/v1/quote-requests/{$request->id}/select/{$provider->id}")
            ->assertOk()
            ->assertJsonPath('data.quote_request.status', QuoteRequest::STATUS_EN_PROCESO);

        $this->assertDatabaseHas('quote_requests', [
            'id' => $request->id,
            'status' => QuoteRequest::STATUS_EN_PROCESO,
        ]);
    }

    public function test_seleccionar_notifica_al_prestador_seleccionado(): void
    {
        $owner = $this->actingAsClient();
        $category = $this->category();
        [$request, $provider] = $this->openRequestWithApplicant($owner, $category);

        $notifier = Mockery::mock(QuoteRequestNotifier::class);
        $notifier->shouldReceive('notifyProviderSelected')
            ->once()
            ->with(Mockery::type(QuoteRequest::class), $provider->id);
        $notifier->shouldReceive('notifyApplicantsOfEdit')->never();
        $notifier->shouldReceive('notifyApplicantsOfClosure')->never();
        $this->app->instance(QuoteRequestNotifier::class, $notifier);

        $this->postJson("/api/v1/quote-requests/{$request->id}/select/{$provider->id}")
            ->assertOk();
    }

    public function test_no_dueno_no_puede_seleccionar_prestador(): void
    {
        $owner = User::factory()->create();
        $category = $this->category();
        [$request, $provider] = $this->openRequestWithApplicant($owner, $category);

        $this->actingAsClient(); // otro usuario

        $this->postJson("/api/v1/quote-requests/{$request->id}/select/{$provider->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('quote_requests', [
            'id' => $request->id,
            'status' => QuoteRequest::STATUS_ABIERTA,
        ]);
    }

    public function test_seleccionar_sin_autenticacion_retorna_401(): void
    {
        $owner = User::factory()->create();
        $category = $this->category();
        [$request, $provider] = $this->openRequestWithApplicant($owner, $category);

        $this->postJson("/api/v1/quote-requests/{$request->id}/select/{$provider->id}")
            ->assertStatus(401);
    }

    public function test_seleccionar_en_solicitud_no_abierta_retorna_409(): void
    {
        $owner = $this->actingAsClient();
        $category = $this->category();
        [$request, $provider] = $this->openRequestWithApplicant($owner, $category);

        $request->update(['status' => QuoteRequest::STATUS_EN_PROCESO]);

        $this->postJson("/api/v1/quote-requests/{$request->id}/select/{$provider->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('quote_requests', [
            'id' => $request->id,
            'status' => QuoteRequest::STATUS_EN_PROCESO,
        ]);
    }

    public function test_seleccionar_prestador_no_postulante_retorna_422(): void
    {
        $owner = $this->actingAsClient();
        $category = $this->category();
        [$request] = $this->openRequestWithApplicant($owner, $category);

        // Un Prestador distinto que NO ha postulado a la solicitud.
        $other = User::factory()->create();
        $other->assignRole('prestador');

        $this->postJson("/api/v1/quote-requests/{$request->id}/select/{$other->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('quote_requests', [
            'id' => $request->id,
            'status' => QuoteRequest::STATUS_ABIERTA,
        ]);
    }

    // --- Requisito 4.3: búsqueda / filtrado de solicitudes (tarea 7.2) -----

    private function makeRequest(ServiceCategory $category, array $overrides = []): QuoteRequest
    {
        $client = User::factory()->create();

        return QuoteRequest::create(array_merge([
            'client_user_id' => $client->id,
            'service_category_id' => $category->id,
            'title' => 'Trabajo',
            'description' => 'Descripción del trabajo',
            'location' => 'Lima',
            'estimated_budget' => 100,
            'status' => QuoteRequest::STATUS_ABIERTA,
        ], $overrides));
    }

    public function test_filtra_solicitudes_por_categoria(): void
    {
        $plomeria = $this->category('plomeria');
        $pintura = $this->category('pintura');
        $this->makeRequest($plomeria, ['title' => 'Fuga']);
        $this->makeRequest($pintura, ['title' => 'Pintar']);

        $this->getJson('/api/v1/quote-requests?category=plomeria')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Fuga');
    }

    public function test_filtra_solicitudes_por_departamento_y_ciudad(): void
    {
        $category = $this->category();

        // Dos ciudades en departamentos distintos del Catálogo_Ubicación.
        $cityA = City::factory()->create();
        $cityB = City::factory()->create();

        $this->makeRequest($category, ['city_id' => $cityA->id]);
        $this->makeRequest($category, ['city_id' => $cityB->id]);

        // Filtro por Departamento: solo la solicitud de la ciudad de ese depto.
        $this->getJson('/api/v1/quote-requests?department_id='.$cityA->department_id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.city_id', $cityA->id);

        // Filtro por Ciudad: acota a esa ciudad concreta.
        $this->getJson('/api/v1/quote-requests?city_id='.$cityB->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.city_id', $cityB->id);
    }

    public function test_filtra_solicitudes_por_rango_de_presupuesto(): void
    {
        $category = $this->category();
        $this->makeRequest($category, ['estimated_budget' => 50]);
        $this->makeRequest($category, ['estimated_budget' => 150]);
        $this->makeRequest($category, ['estimated_budget' => 500]);

        $this->getJson('/api/v1/quote-requests?budget_min=100&budget_max=300')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_rango_de_presupuesto_excluye_solicitudes_sin_presupuesto(): void
    {
        $category = $this->category();
        $this->makeRequest($category, ['estimated_budget' => null]);
        $this->makeRequest($category, ['estimated_budget' => 200]);

        $this->getJson('/api/v1/quote-requests?budget_min=100')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_sin_resultados_retorna_200_con_mensaje(): void
    {
        $category = $this->category();

        // Hay una solicitud en una ciudad, pero se filtra por OTRA ciudad
        // (válida en el catálogo) que no tiene solicitudes.
        $withRequest = City::factory()->create();
        $empty = City::factory()->create();
        $this->makeRequest($category, ['city_id' => $withRequest->id]);

        $this->getJson('/api/v1/quote-requests?city_id='.$empty->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('message', 'No se encontraron solicitudes de cotización con los filtros indicados.');
    }

    public function test_rechaza_budget_max_menor_que_budget_min(): void
    {
        $this->category();

        $this->getJson('/api/v1/quote-requests?budget_min=500&budget_max=100')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['budget_max']);
    }

    public function test_rechaza_categoria_de_filtro_inexistente(): void
    {
        $this->getJson('/api/v1/quote-requests?category=no-existe')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category']);
    }

    // --- Requisito 4.4 / 4.8: postulación de prestadores (tarea 7.2) -------

    private function actingAsProvider(): User
    {
        $user = User::factory()->create();
        $user->assignRole('prestador');
        Sanctum::actingAs($user);

        return $user;
    }

    private function openRequest(ServiceCategory $category, string $status = QuoteRequest::STATUS_ABIERTA): QuoteRequest
    {
        $owner = User::factory()->create();

        return QuoteRequest::create([
            'client_user_id' => $owner->id,
            'service_category_id' => $category->id,
            'title' => 'Instalación',
            'description' => 'Instalar algo',
            'location' => 'Lima',
            'status' => $status,
        ]);
    }

    public function test_prestador_postula_a_solicitud_abierta(): void
    {
        $provider = $this->actingAsProvider();
        $request = $this->openRequest($this->category());

        $this->postJson("/api/v1/quote-requests/{$request->id}/apply")
            ->assertCreated()
            ->assertJsonPath('data.application.provider_user_id', $provider->id)
            ->assertJsonPath('data.application.quote_request_id', $request->id);

        $this->assertDatabaseHas('quote_request_applications', [
            'quote_request_id' => $request->id,
            'provider_user_id' => $provider->id,
        ]);
    }

    public function test_postular_notifica_al_cliente(): void
    {
        $provider = $this->actingAsProvider();
        $request = $this->openRequest($this->category());

        $notifier = Mockery::mock(QuoteRequestNotifier::class);
        $notifier->shouldReceive('notifyClientOfApplication')
            ->once()
            ->with(Mockery::type(QuoteRequest::class), $provider->id);
        $this->app->instance(QuoteRequestNotifier::class, $notifier);

        $this->postJson("/api/v1/quote-requests/{$request->id}/apply")
            ->assertCreated();
    }

    public function test_postulacion_duplicada_retorna_409(): void
    {
        $provider = $this->actingAsProvider();
        $request = $this->openRequest($this->category());

        QuoteRequestApplication::create([
            'quote_request_id' => $request->id,
            'provider_user_id' => $provider->id,
        ]);

        $this->postJson("/api/v1/quote-requests/{$request->id}/apply")
            ->assertStatus(409);

        $this->assertDatabaseCount('quote_request_applications', 1);
    }

    public function test_postular_a_solicitud_no_abierta_retorna_409(): void
    {
        $this->actingAsProvider();
        $request = $this->openRequest($this->category(), QuoteRequest::STATUS_CERRADA);

        $this->postJson("/api/v1/quote-requests/{$request->id}/apply")
            ->assertStatus(409);

        $this->assertDatabaseCount('quote_request_applications', 0);
    }

    public function test_postular_sin_rol_prestador_retorna_403(): void
    {
        $this->actingAsClient(); // solo rol cliente
        $request = $this->openRequest($this->category());

        $this->postJson("/api/v1/quote-requests/{$request->id}/apply")
            ->assertStatus(403);

        $this->assertDatabaseCount('quote_request_applications', 0);
    }

    public function test_postular_sin_autenticacion_retorna_401(): void
    {
        $request = $this->openRequest($this->category());

        $this->postJson("/api/v1/quote-requests/{$request->id}/apply")
            ->assertStatus(401);
    }

    // --- Requisito 4.4: listado de postulaciones (Cliente dueño) -----------

    public function test_cliente_dueno_lista_postulaciones(): void
    {
        $owner = $this->actingAsClient();
        $category = $this->category();
        $request = QuoteRequest::create([
            'client_user_id' => $owner->id,
            'service_category_id' => $category->id,
            'title' => 'Trabajo',
            'description' => 'Desc',
            'location' => 'Lima',
            'status' => QuoteRequest::STATUS_ABIERTA,
        ]);

        $provider = User::factory()->create();
        $provider->assignRole('prestador');
        QuoteRequestApplication::create([
            'quote_request_id' => $request->id,
            'provider_user_id' => $provider->id,
        ]);

        $this->getJson("/api/v1/quote-requests/{$request->id}/applications")
            ->assertOk()
            ->assertJsonCount(1, 'data.applications')
            ->assertJsonPath('data.applications.0.provider_user_id', $provider->id);
    }

    public function test_no_dueno_no_puede_listar_postulaciones(): void
    {
        $owner = User::factory()->create();
        $category = $this->category();
        $request = QuoteRequest::create([
            'client_user_id' => $owner->id,
            'service_category_id' => $category->id,
            'title' => 'Trabajo',
            'description' => 'Desc',
            'location' => 'Lima',
            'status' => QuoteRequest::STATUS_ABIERTA,
        ]);

        $this->actingAsClient(); // otro usuario

        $this->getJson("/api/v1/quote-requests/{$request->id}/applications")
            ->assertStatus(403);
    }

    public function test_listar_postulaciones_sin_autenticacion_retorna_401(): void
    {
        $owner = User::factory()->create();
        $category = $this->category();
        $request = QuoteRequest::create([
            'client_user_id' => $owner->id,
            'service_category_id' => $category->id,
            'title' => 'Trabajo',
            'description' => 'Desc',
            'location' => 'Lima',
            'status' => QuoteRequest::STATUS_ABIERTA,
        ]);

        $this->getJson("/api/v1/quote-requests/{$request->id}/applications")
            ->assertStatus(401);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
