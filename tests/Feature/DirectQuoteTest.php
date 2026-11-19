<?php

namespace Tests\Feature;

use App\Contracts\QuoteNotifier;
use App\Jobs\ExpireDirectQuoteJob;
use App\Jobs\ExpireQuoteJob;
use App\Models\Quote;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests para la tarea 11.1: solicitud y respuesta de Cotización directa
 * (Requisitos 10.1, 10.2, 10.4, 10.5, 10.6).
 *
 *  - POST /quotes/direct crea una Cotización "solicitada" y notifica al
 *    Prestador (Requisito 10.1).
 *  - Rechazo 422 si el Prestador objetivo no tiene rol activo (Requisito 10.5).
 *  - Rechazo 409 al alcanzar 5 solicitudes activas al mismo Prestador (Req 10.6).
 *  - POST /quotes/direct/{id}/respond aplica las reglas del Requisito 5 y pasa
 *    la Cotización a "pendiente" (Requisitos 10.2, 10.4).
 */
class DirectQuoteTest extends TestCase
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

    /**
     * Crea una Cotización directa "solicitada" directamente en BD, para los
     * escenarios de respuesta y de límite activo.
     */
    private function makeSolicitada(User $client, User $provider): Quote
    {
        return Quote::create([
            'provider_user_id' => $provider->id,
            'client_user_id' => $client->id,
            'quote_request_id' => null,
            'service_category_id' => $this->category()->id,
            'type' => Quote::TYPE_DIRECTA,
            'service_description' => 'Solicitud de cotización directa.',
            'total_price' => 0,
            'delivery_days' => 0,
            'expires_at' => now()->startOfDay()->addDays(7),
            'status' => Quote::STATUS_SOLICITADA,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validRespondPayload(array $overrides = []): array
    {
        return array_merge([
            'service_description' => 'Instalación eléctrica completa.',
            'items' => [
                ['description' => 'Mano de obra', 'unit_price' => 150.50, 'quantity' => 2],
                ['description' => 'Materiales', 'unit_price' => 100.00, 'quantity' => 1],
            ],
            'delivery_days' => 10,
            'expiration_days' => 7,
        ], $overrides);
    }

    // --- Requisito 10.1: crear solicitud "solicitada" + notificar -----------

    public function test_solicitar_cotizacion_directa_crea_solicitada_y_notifica(): void
    {
        $client = $this->client();
        $provider = $this->provider();

        $notifier = Mockery::mock(QuoteNotifier::class);
        $notifier->shouldReceive('notifyProviderOfDirectRequest')
            ->once()
            ->with(Mockery::on(fn (Quote $q): bool => $q->provider_user_id === $provider->id
                && $q->client_user_id === $client->id
                && $q->status === Quote::STATUS_SOLICITADA));
        $this->app->instance(QuoteNotifier::class, $notifier);

        Sanctum::actingAs($client);

        $this->postJson('/api/v1/quotes/direct', [
            'provider_user_id' => $provider->id,
            'service_category_id' => $this->category()->id,
            'message' => 'Necesito una cotización para instalación eléctrica.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.quote.status', Quote::STATUS_SOLICITADA)
            ->assertJsonPath('data.quote.type', Quote::TYPE_DIRECTA)
            ->assertJsonPath('data.quote.provider_user_id', $provider->id)
            ->assertJsonPath('data.quote.quote_request_id', null);

        $this->assertDatabaseHas('quotes', [
            'client_user_id' => $client->id,
            'provider_user_id' => $provider->id,
            'type' => Quote::TYPE_DIRECTA,
            'status' => Quote::STATUS_SOLICITADA,
            'quote_request_id' => null,
        ]);
    }

    public function test_solicitar_cotizacion_directa_despacha_expire_job_con_delay_7_dias(): void
    {
        Queue::fake();

        $client = $this->client();
        $provider = $this->provider();

        Sanctum::actingAs($client);

        $this->postJson('/api/v1/quotes/direct', [
            'provider_user_id' => $provider->id,
            'service_category_id' => $this->category()->id,
        ])->assertCreated();

        $expectedDelay = now()->startOfDay()->addDays(7);

        Queue::assertPushed(ExpireDirectQuoteJob::class, function (ExpireDirectQuoteJob $job) use ($expectedDelay): bool {
            $quote = Quote::firstOrFail();

            return $job->quoteId === $quote->id
                && $job->delay !== null
                && $expectedDelay->equalTo($job->delay);
        });
    }

    // --- Requisito 10.5: Prestador objetivo sin rol activo -> 422 -----------

    public function test_solicitar_a_usuario_sin_rol_prestador_es_rechazado(): void
    {
        $client = $this->client();
        $notProvider = $this->client(); // solo rol cliente

        Sanctum::actingAs($client);

        $this->postJson('/api/v1/quotes/direct', [
            'provider_user_id' => $notProvider->id,
            'service_category_id' => $this->category()->id,
        ])->assertStatus(422);

        $this->assertDatabaseCount('quotes', 0);
    }

    // --- Requisito 10.6: límite de 5 solicitudes activas -> 409 -------------

    public function test_sexta_solicitud_activa_al_mismo_prestador_es_rechazada(): void
    {
        $client = $this->client();
        $provider = $this->provider();

        // 5 solicitudes activas (solicitada / sin_respuesta) hacia el mismo Prestador.
        for ($i = 0; $i < 4; $i++) {
            $this->makeSolicitada($client, $provider);
        }
        $sinRespuesta = $this->makeSolicitada($client, $provider);
        $sinRespuesta->update(['status' => Quote::STATUS_SIN_RESPUESTA]);

        Sanctum::actingAs($client);

        $this->postJson('/api/v1/quotes/direct', [
            'provider_user_id' => $provider->id,
            'service_category_id' => $this->category()->id,
        ])->assertStatus(409);

        // No se creó una sexta.
        $this->assertSame(5, Quote::query()
            ->where('client_user_id', $client->id)
            ->where('provider_user_id', $provider->id)
            ->count());
    }

    public function test_limite_no_cuenta_solicitudes_a_otro_prestador(): void
    {
        $client = $this->client();
        $providerA = $this->provider();
        $providerB = $this->provider();

        // 5 activas hacia A no deben bloquear una solicitud a B.
        for ($i = 0; $i < 5; $i++) {
            $this->makeSolicitada($client, $providerA);
        }

        Sanctum::actingAs($client);

        $this->postJson('/api/v1/quotes/direct', [
            'provider_user_id' => $providerB->id,
            'service_category_id' => $this->category()->id,
        ])->assertCreated();
    }

    // --- Requisitos 10.2/10.4: responder aplica reglas del Requisito 5 ------

    public function test_prestador_responde_solicitud_directa_pasa_a_pendiente(): void
    {
        $client = $this->client();
        $provider = $this->provider();
        $quote = $this->makeSolicitada($client, $provider);

        Sanctum::actingAs($provider);

        $response = $this->postJson("/api/v1/quotes/direct/{$quote->id}/respond", $this->validRespondPayload())
            ->assertOk()
            ->assertJsonPath('data.quote.status', Quote::STATUS_PENDIENTE)
            ->assertJsonPath('data.quote.delivery_days', 10);

        // total = 150.50*2 + 100.00*1 = 401.00 (Requisito 5.2). Comparación
        // numérica laxa: el JSON serializa 401.00 como 401.
        $this->assertEqualsWithDelta(401.0, (float) $response->json('data.quote.total_price'), 0.001);

        $this->assertDatabaseHas('quotes', [
            'id' => $quote->id,
            'status' => Quote::STATUS_PENDIENTE,
            'type' => Quote::TYPE_DIRECTA,
        ]);
        $this->assertDatabaseCount('quote_items', 2);
    }

    public function test_responder_despacha_expire_quote_job_estandar(): void
    {
        Queue::fake();

        $client = $this->client();
        $provider = $this->provider();
        $quote = $this->makeSolicitada($client, $provider);

        Sanctum::actingAs($provider);

        $this->postJson("/api/v1/quotes/direct/{$quote->id}/respond", $this->validRespondPayload([
            'expiration_days' => 5,
        ]))->assertOk();

        $expectedDelay = now()->startOfDay()->addDays(5);

        Queue::assertPushed(ExpireQuoteJob::class, function (ExpireQuoteJob $job) use ($quote, $expectedDelay): bool {
            return $job->quoteId === $quote->id
                && $job->delay !== null
                && $expectedDelay->equalTo($job->delay);
        });
    }

    public function test_responder_fuera_de_rango_es_422_sin_persistir(): void
    {
        $client = $this->client();
        $provider = $this->provider();
        $quote = $this->makeSolicitada($client, $provider);

        Sanctum::actingAs($provider);

        $this->postJson("/api/v1/quotes/direct/{$quote->id}/respond", $this->validRespondPayload([
            'delivery_days' => 0, // fuera de rango (1-365)
        ]))->assertStatus(422);

        $this->assertSame(Quote::STATUS_SOLICITADA, $quote->fresh()->status);
        $this->assertDatabaseCount('quote_items', 0);
    }

    public function test_solo_el_prestador_objetivo_puede_responder(): void
    {
        $client = $this->client();
        $provider = $this->provider();
        $otherProvider = $this->provider();
        $quote = $this->makeSolicitada($client, $provider);

        Sanctum::actingAs($otherProvider);

        $this->postJson("/api/v1/quotes/direct/{$quote->id}/respond", $this->validRespondPayload())
            ->assertStatus(403);

        $this->assertSame(Quote::STATUS_SOLICITADA, $quote->fresh()->status);
    }

    public function test_responder_solicitud_no_solicitada_es_409(): void
    {
        $client = $this->client();
        $provider = $this->provider();
        $quote = $this->makeSolicitada($client, $provider);
        $quote->update(['status' => Quote::STATUS_SIN_RESPUESTA]);

        Sanctum::actingAs($provider);

        $this->postJson("/api/v1/quotes/direct/{$quote->id}/respond", $this->validRespondPayload())
            ->assertStatus(409);
    }

    // --- Requisito 18: categor�a obligatoria ---------------------------------

    public function test_solicitar_cotizacion_directa_guarda_la_categoria(): void
    {
        $category = $this->category();
        $provider = $this->provider();

        Sanctum::actingAs($this->client());

        $this->postJson('/api/v1/quotes/direct', [
            'provider_user_id' => $provider->id,
            'service_category_id' => $category->id,
        ])->assertCreated();

        $this->assertDatabaseHas('quotes', [
            'provider_user_id' => $provider->id,
            'type' => Quote::TYPE_DIRECTA,
            'service_category_id' => $category->id,
        ]);
    }

    public function test_solicitar_sin_categoria_es_422_y_no_crea_cotizacion(): void
    {
        Queue::fake();

        $provider = $this->provider();

        Sanctum::actingAs($this->client());

        $this->postJson('/api/v1/quotes/direct', [
            'provider_user_id' => $provider->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_category_id');

        $this->assertDatabaseCount('quotes', 0);
        Queue::assertNotPushed(ExpireDirectQuoteJob::class);
    }

    public function test_solicitar_con_categoria_inexistente_es_422(): void
    {
        $provider = $this->provider();

        Sanctum::actingAs($this->client());

        $this->postJson('/api/v1/quotes/direct', [
            'provider_user_id' => $provider->id,
            'service_category_id' => 999999,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_category_id');

        $this->assertDatabaseCount('quotes', 0);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
