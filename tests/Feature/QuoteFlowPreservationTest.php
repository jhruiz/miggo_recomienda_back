<?php

namespace Tests\Feature;

use App\Jobs\ExpireQuoteJob;
use App\Models\ChatChannel;
use App\Models\Notification;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Models\QuoteRequestApplication;
use App\Models\ServiceCategory;
use App\Models\User;
use Eris\Generators;
use Eris\TestTrait;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Pruebas de PRESERVACIÓN (Property 2) de la spec flujo-cotizaciones-fixes.
 *
 * Fijan el comportamiento observado sobre el código SIN corregir para entradas
 * fuera de la condición de defecto (solicitudes ajenas, usuarios no dueños).
 * Deben pasar antes y después de la corrección.
 *
 * Validates: Requirements 3.1, 3.2, 3.3, 3.4, 3.5, 3.7, 3.8, 3.10
 */
class QuoteFlowPreservationTest extends TestCase
{
    use RefreshDatabase;
    use TestTrait;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['cliente', 'prestador'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
    }

    // --- Helpers -----------------------------------------------------------

    private function category(): ServiceCategory
    {
        return ServiceCategory::firstOrCreate(['slug' => 'plomeria'], ['name' => 'Plomeria']);
    }

    private function client(): User
    {
        $user = User::factory()->create();
        $user->assignRole('cliente');

        return $user;
    }

    private function provider(): User
    {
        $user = User::factory()->create();
        $user->assignRole('cliente');
        $user->assignRole('prestador');

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
     * @param  array<int, array{0: float, 1: int}>  $items  [unit_price, quantity]
     * @return array<string, mixed>
     */
    private function quotePayload(QuoteRequest $request, array $items = [[100.5, 2], [25.25, 4]]): array
    {
        return [
            'quote_request_id' => $request->id,
            'service_description' => 'Instalación eléctrica completa con materiales.',
            'items' => array_map(
                fn (array $i, int $n): array => [
                    'description' => "Ítem {$n}",
                    'unit_price' => $i[0],
                    'quantity' => $i[1],
                ],
                $items,
                array_keys($items),
            ),
            'delivery_days' => 10,
            'expiration_days' => 7,
        ];
    }

    private function pendingQuote(QuoteRequest $request, User $provider): Quote
    {
        return Quote::create([
            'provider_user_id' => $provider->id,
            'client_user_id' => $request->client_user_id,
            'quote_request_id' => $request->id,
            'type' => Quote::TYPE_PUBLICA,
            'service_description' => 'Servicio',
            'total_price' => 50,
            'delivery_days' => 3,
            'expires_at' => now()->startOfDay()->addDays(7),
            'status' => Quote::STATUS_PENDIENTE,
        ]);
    }

    // --- 3.1: postulación a solicitud ajena --------------------------------

    public function test_postular_a_solicitud_ajena_crea_postulacion_canal_y_notifica(): void
    {
        $provider = $this->provider();
        $request = $this->openRequest();
        Sanctum::actingAs($provider);

        $this->postJson("/api/v1/quote-requests/{$request->id}/apply")
            ->assertCreated()
            ->assertJsonPath('data.application.provider_user_id', $provider->id);

        $application = QuoteRequestApplication::firstOrFail();
        $this->assertSame($request->id, $application->quote_request_id);

        $this->assertDatabaseHas('chat_channels', [
            'quote_request_application_id' => $application->id,
            'status' => ChatChannel::STATUS_PRE_ACEPTACION,
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $request->client_user_id,
            'type' => 'quote_request.application_received',
        ]);
    }

    public function test_postulacion_duplicada_y_solicitud_no_abierta_dan_409(): void
    {
        $provider = $this->provider();
        $request = $this->openRequest();
        $closed = $this->openRequest(null, QuoteRequest::STATUS_CERRADA);
        Sanctum::actingAs($provider);

        $this->postJson("/api/v1/quote-requests/{$request->id}/apply")->assertCreated();
        $this->postJson("/api/v1/quote-requests/{$request->id}/apply")->assertStatus(409);
        $this->postJson("/api/v1/quote-requests/{$closed->id}/apply")->assertStatus(409);

        $this->assertDatabaseCount('quote_request_applications', 1);
    }

    // --- 3.3: restricción por rol ------------------------------------------

    public function test_usuario_solo_cliente_no_puede_postular_ni_cotizar(): void
    {
        $request = $this->openRequest();
        Sanctum::actingAs($this->client());

        $this->postJson("/api/v1/quote-requests/{$request->id}/apply")->assertStatus(403);
        $this->postJson('/api/v1/quotes', $this->quotePayload($request))->assertStatus(403);

        $this->assertDatabaseCount('quote_request_applications', 0);
        $this->assertDatabaseCount('quotes', 0);
    }

    // --- 3.2: cotización a solicitud ajena ---------------------------------

    public function test_cotizacion_valida_a_solicitud_ajena_notifica_y_encola_expiracion(): void
    {
        Queue::fake();
        $provider = $this->provider();
        $request = $this->openRequest();
        Sanctum::actingAs($provider);

        $this->postJson('/api/v1/quotes', $this->quotePayload($request))
            ->assertCreated()
            ->assertJsonPath('data.quote.status', Quote::STATUS_PENDIENTE)
            ->assertJsonPath('data.quote.total_price', fn ($v): bool => (float) $v === 302.0);

        Queue::assertPushed(ExpireQuoteJob::class);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $request->client_user_id,
            'type' => 'quote.received',
        ]);
    }

    public function test_cotizacion_invalida_es_422_y_solicitud_no_abierta_es_409(): void
    {
        $provider = $this->provider();
        $request = $this->openRequest();
        $closed = $this->openRequest(null, QuoteRequest::STATUS_CERRADA);
        Sanctum::actingAs($provider);

        $this->postJson('/api/v1/quotes', ['quote_request_id' => $request->id])->assertStatus(422);
        $this->postJson('/api/v1/quotes', $this->quotePayload($closed))->assertStatus(409);

        $this->assertDatabaseCount('quotes', 0);
    }

    // --- 3.4 / 3.5: permisos de lectura y acciones -------------------------

    public function test_comparativa_es_403_para_no_dueno_con_mensaje_exacto(): void
    {
        $request = $this->openRequest();
        Sanctum::actingAs($this->provider());

        $this->getJson("/api/v1/quotes/request/{$request->id}")
            ->assertStatus(403)
            ->assertJsonPath('message', 'No tienes permiso para ver las cotizaciones de esta solicitud.');
    }

    public function test_comparativa_y_detalle_funcionan_para_las_partes(): void
    {
        $request = $this->openRequest();
        $provider = $this->provider();
        $quote = $this->pendingQuote($request, $provider);

        Sanctum::actingAs(User::find($request->client_user_id));
        $this->getJson("/api/v1/quotes/request/{$request->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.quotes');
        $this->getJson("/api/v1/quotes/{$quote->id}")->assertOk();

        Sanctum::actingAs($provider);
        $this->getJson("/api/v1/quotes/{$quote->id}")
            ->assertOk()
            ->assertJsonPath('data.quote.id', $quote->id);
    }

    public function test_detalle_de_cotizacion_es_403_para_no_partes(): void
    {
        $request = $this->openRequest();
        $quote = $this->pendingQuote($request, $this->provider());

        Sanctum::actingAs($this->provider());

        $this->getJson("/api/v1/quotes/{$quote->id}")->assertStatus(403);
    }

    public function test_present_conserva_todos_los_campos_actuales(): void
    {
        $request = $this->openRequest();
        $provider = $this->provider();
        $quote = $this->pendingQuote($request, $provider);
        Sanctum::actingAs($provider);

        $response = $this->getJson("/api/v1/quotes/{$quote->id}")->assertOk();
        $data = $response->json('data.quote');

        foreach ([
            'id', 'provider_user_id', 'provider_name', 'client_user_id', 'client_name',
            'quote_request_id', 'type', 'service_description', 'total_price',
            'delivery_days', 'expires_at', 'status', 'items', 'attachments',
            'created_at', 'updated_at',
        ] as $key) {
            $this->assertArrayHasKey($key, $data);
        }

        $this->assertSame($quote->id, $data['id']);
        $this->assertSame($provider->id, $data['provider_user_id']);
        $this->assertSame($request->client_user_id, $data['client_user_id']);
        $this->assertSame($request->id, $data['quote_request_id']);
        $this->assertSame(Quote::TYPE_PUBLICA, $data['type']);
        $this->assertSame(Quote::STATUS_PENDIENTE, $data['status']);
        $this->assertSame(50.0, (float) $data['total_price']);
        $this->assertSame(3, $data['delivery_days']);
    }

    public function test_aceptar_y_rechazar_solo_el_cliente_receptor(): void
    {
        $request = $this->openRequest();
        $provider = $this->provider();
        $quote = $this->pendingQuote($request, $provider);

        foreach ([$provider, $this->client()] as $intruder) {
            Sanctum::actingAs($intruder);
            $this->postJson("/api/v1/quotes/{$quote->id}/accept")->assertStatus(403);
            $this->postJson("/api/v1/quotes/{$quote->id}/reject")->assertStatus(403);
        }

        $this->assertSame(Quote::STATUS_PENDIENTE, $quote->fresh()->status);

        Sanctum::actingAs(User::find($request->client_user_id));
        $this->postJson("/api/v1/quotes/{$quote->id}/reject")->assertOk();
        $this->assertSame(Quote::STATUS_RECHAZADA, $quote->fresh()->status);
    }

    public function test_responder_directa_solo_el_prestador_objetivo(): void
    {
        $client = $this->client();
        $target = $this->provider();
        $quote = Quote::create([
            'provider_user_id' => $target->id,
            'client_user_id' => $client->id,
            'quote_request_id' => null,
            'service_category_id' => $this->category()->id,
            'type' => Quote::TYPE_DIRECTA,
            'service_description' => 'Solicitud directa.',
            'total_price' => 0,
            'delivery_days' => 0,
            'expires_at' => now()->startOfDay()->addDays(7),
            'status' => Quote::STATUS_SOLICITADA,
        ]);
        $payload = [
            'service_description' => 'Respuesta',
            'items' => [['description' => 'Mano de obra', 'unit_price' => 10, 'quantity' => 1]],
            'delivery_days' => 5,
            'expiration_days' => 7,
        ];

        Sanctum::actingAs($this->provider());
        $this->postJson("/api/v1/quotes/direct/{$quote->id}/respond", $payload)->assertStatus(403);
        $this->assertSame(Quote::STATUS_SOLICITADA, $quote->fresh()->status);

        Sanctum::actingAs($target);
        $this->postJson("/api/v1/quotes/direct/{$quote->id}/respond", $payload)->assertOk();
        $this->assertSame(Quote::STATUS_PENDIENTE, $quote->fresh()->status);
    }

    // --- 3.7: notificaciones -----------------------------------------------

    public function test_notificaciones_orden_desc_aislamiento_contador_y_claves_originales(): void
    {
        $user = $this->client();
        $other = $this->client();
        $payload = ['quote_id' => 5, 'quote_request_id' => 7, 'provider_user_id' => 9];

        $make = fn (User $u, bool $read, string $at): Notification => tap(
            Notification::create([
                'user_id' => $u->id,
                'type' => 'quote.received',
                'data' => $payload,
                'is_read' => $read,
                'read_at' => $read ? now() : null,
            ]),
            fn (Notification $n) => $n->forceFill(['created_at' => $at])->save(),
        );

        $old = $make($user, false, now()->subDays(2)->toDateTimeString());
        $new = $make($user, false, now()->toDateTimeString());
        $mid = $make($user, true, now()->subDay()->toDateTimeString());
        $make($other, false, now()->toDateTimeString());

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/notifications')->assertOk()
            ->assertJsonCount(3, 'data.notifications')
            ->assertJsonPath('data.notifications.0.id', $new->id)
            ->assertJsonPath('data.notifications.1.id', $mid->id)
            ->assertJsonPath('data.notifications.2.id', $old->id);

        foreach ($response->json('data.notifications') as $n) {
            foreach ($payload as $key => $value) {
                $this->assertSame($value, $n['data'][$key]);
            }
        }

        $this->getJson('/api/v1/notifications/unread-count')->assertOk()
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.display', '2');
    }

    // --- 3.8: rutas numéricas siguen resolviendo ---------------------------

    public function test_rutas_numericas_resuelven_a_sus_controladores(): void
    {
        $request = $this->openRequest();
        $provider = $this->provider();
        $quote = $this->pendingQuote($request, $provider);

        $this->getJson("/api/v1/quote-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.quote_request.id', $request->id);

        Sanctum::actingAs($provider);
        $this->getJson("/api/v1/quotes/{$quote->id}")
            ->assertOk()
            ->assertJsonPath('data.quote.id', $quote->id);

        $routes = app('router')->getRoutes();
        $this->assertStringContainsString(
            'QuoteRequestController@show',
            $routes->match(Request::create("/api/v1/quote-requests/{$request->id}", 'GET'))->getActionName(),
        );
        $this->assertStringContainsString(
            'QuoteController@show',
            $routes->match(Request::create("/api/v1/quotes/{$quote->id}", 'GET'))->getActionName(),
        );
    }

    // --- Propiedades (Eris) ------------------------------------------------

    /**
     * Property 2: para cualquier lista válida de ítems sobre una solicitud
     * ajena, total = Σ(unit_price × quantity) a 2 decimales, estado pendiente.
     *
     * Validates: Requirements 3.2
     */
    #[Group('property')]
    public function test_property_total_de_cotizacion_es_suma_de_subtotales(): void
    {
        Queue::fake();

        $this->limitTo(100)
            ->forAll(
                Generators::bind(
                    Generators::choose(1, 10),
                    fn (int $n) => Generators::vector($n, Generators::tuple(
                        Generators::choose(1, 100000), // centavos
                        Generators::choose(1, 20),
                    )),
                ),
            )
            ->then(function (array $items): void {
                $provider = $this->provider();
                $request = $this->openRequest();
                Sanctum::actingAs($provider);

                $pairs = array_map(fn (array $i): array => [$i[0] / 100, $i[1]], $items);
                $expected = round(array_sum(array_map(fn (array $p): float => $p[0] * $p[1], $pairs)), 2);

                $this->postJson('/api/v1/quotes', $this->quotePayload($request, $pairs))
                    ->assertCreated()
                    ->assertJsonPath('data.quote.status', Quote::STATUS_PENDIENTE)
                    ->assertJsonPath(
                        'data.quote.total_price',
                        fn ($v): bool => abs((float) $v - $expected) < 0.005,
                    );
            });
    }

    /**
     * Property 2: postular a solicitud ajena siempre crea exactamente una
     * postulación y notifica al dueño; la segunda postulación es 409.
     *
     * Validates: Requirements 3.1
     */
    #[Group('property')]
    public function test_property_postulacion_ajena_crea_una_y_duplicada_es_409(): void
    {
        $this->limitTo(100)
            ->forAll(Generators::bool())
            ->then(function (bool $withBothRoles): void {
                $provider = $withBothRoles ? $this->provider() : tap(User::factory()->create(), fn ($u) => $u->assignRole('prestador'));
                $request = $this->openRequest();
                Sanctum::actingAs($provider);

                $this->postJson("/api/v1/quote-requests/{$request->id}/apply")->assertCreated();
                $this->postJson("/api/v1/quote-requests/{$request->id}/apply")->assertStatus(409);

                $this->assertSame(
                    1,
                    QuoteRequestApplication::where('quote_request_id', $request->id)->count(),
                );
                $this->assertSame(
                    1,
                    Notification::where('user_id', $request->client_user_id)
                        ->where('type', 'quote_request.application_received')->count(),
                );
            });
    }

    /**
     * Property 2: un usuario que no es parte recibe siempre 403 en el detalle
     * y en la comparativa, sea cual sea el total de la cotización.
     *
     * Validates: Requirements 3.4, 3.5
     */
    #[Group('property')]
    public function test_property_no_partes_reciben_403(): void
    {
        $this->limitTo(100)
            ->forAll(Generators::choose(1, 100000))
            ->then(function (int $total): void {
                $request = $this->openRequest();
                $quote = $this->pendingQuote($request, $this->provider());
                $quote->forceFill(['total_price' => $total])->save();

                Sanctum::actingAs($this->provider());

                $this->getJson("/api/v1/quotes/{$quote->id}")->assertStatus(403);
                $this->getJson("/api/v1/quotes/request/{$request->id}")->assertStatus(403);
                $this->postJson("/api/v1/quotes/{$quote->id}/accept")->assertStatus(403);
            });
    }
}
