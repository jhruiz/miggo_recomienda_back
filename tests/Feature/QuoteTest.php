<?php

namespace Tests\Feature;

use App\Contracts\QuoteNotifier;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests para la tarea 8.1: creación de Cotizaciones con ítems, cálculo
 * del total, adjuntos, notificación y vista comparativa (Requisitos 5.1, 5.2,
 * 5.3, 5.4, 5.5, 5.6, 6.1, 6.5).
 */
class QuoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['cliente', 'prestador'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
    }

    private function category(string $slug = 'plomeria'): ServiceCategory
    {
        return ServiceCategory::firstOrCreate(
            ['slug' => $slug],
            ['name' => ucfirst($slug)],
        );
    }

    private function client(): User
    {
        $user = User::factory()->create();
        $user->assignRole('cliente');

        return $user;
    }

    private function actingAsProvider(): User
    {
        $user = User::factory()->create();
        $user->assignRole('cliente');
        $user->assignRole('prestador');
        Sanctum::actingAs($user);

        return $user;
    }

    private function openRequest(?User $owner = null, string $status = QuoteRequest::STATUS_ABIERTA): QuoteRequest
    {
        $owner ??= $this->client();

        return QuoteRequest::create([
            'client_user_id' => $owner->id,
            'service_category_id' => $this->category()->id,
            'title' => 'Instalación eléctrica',
            'description' => 'Instalar tomas en la cocina',
            'location' => 'Lima',
            'status' => $status,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(QuoteRequest $request, array $overrides = []): array
    {
        return array_merge([
            'quote_request_id' => $request->id,
            'service_description' => 'Instalación eléctrica completa con materiales.',
            'items' => [
                ['description' => 'Mano de obra', 'unit_price' => 100.50, 'quantity' => 2],
                ['description' => 'Cableado', 'unit_price' => 25.25, 'quantity' => 4],
            ],
            'delivery_days' => 10,
            'expiration_days' => 7,
        ], $overrides);
    }

    // --- Requisito 5.1/5.2/5.4/6.1: creación exitosa -----------------------

    public function test_prestador_crea_cotizacion_pendiente_con_total_calculado(): void
    {
        $provider = $this->actingAsProvider();
        $request = $this->openRequest();

        // total = 100.50*2 + 25.25*4 = 201.00 + 101.00 = 302.00
        $response = $this->postJson('/api/v1/quotes', $this->validPayload($request));

        $response->assertCreated()
            ->assertJsonPath('data.quote.status', Quote::STATUS_PENDIENTE)
            ->assertJsonPath('data.quote.type', Quote::TYPE_PUBLICA)
            ->assertJsonPath('data.quote.total_price', fn ($value): bool => (float) $value === 302.0)
            ->assertJsonPath('data.quote.client_user_id', $request->client_user_id)
            ->assertJsonPath('data.quote.provider_user_id', $provider->id)
            ->assertJsonCount(2, 'data.quote.items');

        $this->assertDatabaseHas('quotes', [
            'provider_user_id' => $provider->id,
            'client_user_id' => $request->client_user_id,
            'quote_request_id' => $request->id,
            'status' => Quote::STATUS_PENDIENTE,
            'total_price' => 302.00,
        ]);

        $this->assertDatabaseCount('quote_items', 2);
        $this->assertDatabaseHas('quote_items', [
            'description' => 'Mano de obra',
            'unit_price' => 100.50,
            'quantity' => 2,
            'subtotal' => 201.00,
        ]);
    }

    public function test_client_user_id_se_deriva_del_dueno_de_la_solicitud(): void
    {
        $this->actingAsProvider();
        $owner = $this->client();
        $request = $this->openRequest($owner);

        // Un client_user_id espurio en el payload debe ignorarse.
        $this->postJson('/api/v1/quotes', $this->validPayload($request, ['client_user_id' => 999999]))
            ->assertCreated()
            ->assertJsonPath('data.quote.client_user_id', $owner->id);
    }

    public function test_expires_at_se_calcula_desde_expiration_days(): void
    {
        $this->actingAsProvider();
        $request = $this->openRequest();

        $expected = now()->startOfDay()->addDays(7)->toDateString();

        $this->postJson('/api/v1/quotes', $this->validPayload($request, ['expiration_days' => 7]))
            ->assertCreated()
            ->assertJsonPath('data.quote.expires_at', $expected);
    }

    // --- Requisito 5.4: notificación al Cliente receptor -------------------

    public function test_crear_cotizacion_notifica_al_cliente(): void
    {
        $this->actingAsProvider();
        $request = $this->openRequest();

        $notifier = Mockery::mock(QuoteNotifier::class);
        $notifier->shouldReceive('notifyClientOfNewQuote')
            ->once()
            ->with(Mockery::type(Quote::class));
        $this->app->instance(QuoteNotifier::class, $notifier);

        $this->postJson('/api/v1/quotes', $this->validPayload($request))
            ->assertCreated();
    }

    // --- Requisito 5.3: adjuntos -------------------------------------------

    public function test_adjunta_hasta_cinco_imagenes(): void
    {
        $this->actingAsProvider();
        $request = $this->openRequest();

        $attachments = [
            UploadedFile::fake()->image('a.jpg'),
            UploadedFile::fake()->image('b.png'),
            UploadedFile::fake()->image('c.webp'),
        ];

        $response = $this->postJson('/api/v1/quotes', $this->validPayload($request, [
            'attachments' => $attachments,
        ]));

        $response->assertCreated()
            ->assertJsonCount(3, 'data.quote.attachments');

        $quote = Quote::firstOrFail();
        $this->assertCount(3, $quote->getMedia(Quote::ATTACHMENTS_MEDIA_COLLECTION));
    }

    public function test_rechaza_mas_de_cinco_adjuntos(): void
    {
        $this->actingAsProvider();
        $request = $this->openRequest();

        $attachments = [];
        for ($i = 0; $i < 6; $i++) {
            $attachments[] = UploadedFile::fake()->image("img{$i}.jpg");
        }

        $this->postJson('/api/v1/quotes', $this->validPayload($request, ['attachments' => $attachments]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['attachments']);

        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_rechaza_adjunto_de_tipo_no_permitido(): void
    {
        $this->actingAsProvider();
        $request = $this->openRequest();

        $this->postJson('/api/v1/quotes', $this->validPayload($request, [
            'attachments' => [UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf')],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['attachments.0']);

        $this->assertDatabaseCount('quotes', 0);
    }

    // --- Requisito 5.5: validación de rangos (422 sin persistir) -----------

    public function test_rechaza_precio_unitario_fuera_de_rango(): void
    {
        $this->actingAsProvider();
        $request = $this->openRequest();

        $this->postJson('/api/v1/quotes', $this->validPayload($request, [
            'items' => [['description' => 'X', 'unit_price' => 0, 'quantity' => 1]],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.unit_price']);

        $this->assertDatabaseCount('quotes', 0);
        $this->assertDatabaseCount('quote_items', 0);
    }

    public function test_rechaza_precio_unitario_mayor_al_maximo(): void
    {
        $this->actingAsProvider();
        $request = $this->openRequest();

        $this->postJson('/api/v1/quotes', $this->validPayload($request, [
            'items' => [['description' => 'X', 'unit_price' => 1000000000, 'quantity' => 1]],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.unit_price']);
    }

    public function test_rechaza_cantidad_fuera_de_rango(): void
    {
        $this->actingAsProvider();
        $request = $this->openRequest();

        $this->postJson('/api/v1/quotes', $this->validPayload($request, [
            'items' => [['description' => 'X', 'unit_price' => 10, 'quantity' => 10000]],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.quantity']);
    }

    public function test_rechaza_mas_de_cincuenta_items(): void
    {
        $this->actingAsProvider();
        $request = $this->openRequest();

        $items = [];
        for ($i = 0; $i < 51; $i++) {
            $items[] = ['description' => "Item {$i}", 'unit_price' => 10, 'quantity' => 1];
        }

        $this->postJson('/api/v1/quotes', $this->validPayload($request, ['items' => $items]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items']);

        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_rechaza_sin_items(): void
    {
        $this->actingAsProvider();
        $request = $this->openRequest();

        $this->postJson('/api/v1/quotes', $this->validPayload($request, ['items' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    public function test_rechaza_descripcion_mayor_a_1000(): void
    {
        $this->actingAsProvider();
        $request = $this->openRequest();

        $this->postJson('/api/v1/quotes', $this->validPayload($request, [
            'service_description' => str_repeat('a', 1001),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['service_description']);
    }

    public function test_rechaza_delivery_days_fuera_de_rango(): void
    {
        $this->actingAsProvider();
        $request = $this->openRequest();

        $this->postJson('/api/v1/quotes', $this->validPayload($request, ['delivery_days' => 366]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['delivery_days']);

        $this->postJson('/api/v1/quotes', $this->validPayload($request, ['delivery_days' => 0]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['delivery_days']);
    }

    // --- Requisitos 6.1/6.5: rango de expiración (1-30 días) ---------------

    public function test_rechaza_expiration_days_mayor_a_30(): void
    {
        $this->actingAsProvider();
        $request = $this->openRequest();

        $this->postJson('/api/v1/quotes', $this->validPayload($request, ['expiration_days' => 31]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['expiration_days']);

        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_rechaza_expiration_days_menor_a_1(): void
    {
        $this->actingAsProvider();
        $request = $this->openRequest();

        $this->postJson('/api/v1/quotes', $this->validPayload($request, ['expiration_days' => 0]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['expiration_days']);
    }

    public function test_acepta_limites_de_expiration_days(): void
    {
        $this->actingAsProvider();

        $this->postJson('/api/v1/quotes', $this->validPayload($this->openRequest(), ['expiration_days' => 1]))
            ->assertCreated();

        $this->postJson('/api/v1/quotes', $this->validPayload($this->openRequest(), ['expiration_days' => 30]))
            ->assertCreated();
    }

    // --- Autorización ------------------------------------------------------

    public function test_crear_sin_autenticacion_retorna_401(): void
    {
        $request = $this->openRequest();

        $this->postJson('/api/v1/quotes', $this->validPayload($request))
            ->assertStatus(401);
    }

    public function test_crear_sin_rol_prestador_retorna_403(): void
    {
        $user = $this->client();
        Sanctum::actingAs($user);
        $request = $this->openRequest();

        $this->postJson('/api/v1/quotes', $this->validPayload($request))
            ->assertStatus(403);

        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_crear_sobre_solicitud_no_abierta_retorna_409(): void
    {
        $this->actingAsProvider();
        $request = $this->openRequest(null, QuoteRequest::STATUS_CERRADA);

        $this->postJson('/api/v1/quotes', $this->validPayload($request))
            ->assertStatus(409);

        $this->assertDatabaseCount('quotes', 0);
    }

    public function test_rechaza_solicitud_inexistente(): void
    {
        $this->actingAsProvider();

        $this->postJson('/api/v1/quotes', [
            'quote_request_id' => 999999,
            'service_description' => 'Desc',
            'items' => [['description' => 'X', 'unit_price' => 10, 'quantity' => 1]],
            'delivery_days' => 5,
            'expiration_days' => 5,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['quote_request_id']);
    }

    // --- GET /quotes/{id} --------------------------------------------------

    public function test_prestador_ve_su_cotizacion(): void
    {
        $provider = $this->actingAsProvider();
        $request = $this->openRequest();

        $quote = app(\App\Services\QuoteService::class)->createQuote(
            $provider,
            $this->validPayload($request),
        );

        $this->getJson("/api/v1/quotes/{$quote->id}")
            ->assertOk()
            ->assertJsonPath('data.quote.id', $quote->id);
    }

    public function test_cliente_receptor_ve_la_cotizacion(): void
    {
        $provider = $this->actingAsProvider();
        $owner = $this->client();
        $request = $this->openRequest($owner);

        $quote = app(\App\Services\QuoteService::class)->createQuote(
            $provider,
            $this->validPayload($request),
        );

        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/quotes/{$quote->id}")
            ->assertOk()
            ->assertJsonPath('data.quote.id', $quote->id);
    }

    public function test_tercero_no_puede_ver_la_cotizacion(): void
    {
        $provider = $this->actingAsProvider();
        $request = $this->openRequest();

        $quote = app(\App\Services\QuoteService::class)->createQuote(
            $provider,
            $this->validPayload($request),
        );

        $stranger = $this->client();
        Sanctum::actingAs($stranger);

        $this->getJson("/api/v1/quotes/{$quote->id}")
            ->assertStatus(403);
    }

    // --- Requisito 5.6: vista comparativa ----------------------------------

    public function test_cliente_dueno_ve_cotizaciones_ordenadas_desc(): void
    {
        $owner = $this->client();
        $request = $this->openRequest($owner);

        $providerA = $this->actingAsProvider();
        $service = app(\App\Services\QuoteService::class);

        $first = $service->createQuote($providerA, $this->validPayload($request, [
            'service_description' => 'Primera',
        ]));
        // Forzamos una fecha de creación anterior para la primera cotización.
        $first->forceFill(['created_at' => now()->subMinutes(5)])->save();

        $providerB = $this->actingAsProvider();
        $second = $service->createQuote($providerB, $this->validPayload($request, [
            'service_description' => 'Segunda',
        ]));

        Sanctum::actingAs($owner);

        $this->getJson("/api/v1/quotes/request/{$request->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.quotes')
            ->assertJsonPath('data.quotes.0.id', $second->id)
            ->assertJsonPath('data.quotes.1.id', $first->id);
    }

    public function test_no_dueno_no_puede_ver_vista_comparativa(): void
    {
        $owner = $this->client();
        $request = $this->openRequest($owner);

        $this->actingAsProvider(); // otro usuario

        $this->getJson("/api/v1/quotes/request/{$request->id}")
            ->assertStatus(403);
    }

    public function test_vista_comparativa_sin_autenticacion_retorna_401(): void
    {
        $request = $this->openRequest();

        $this->getJson("/api/v1/quotes/request/{$request->id}")
            ->assertStatus(401);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
