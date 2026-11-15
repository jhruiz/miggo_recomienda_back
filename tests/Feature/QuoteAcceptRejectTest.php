<?php

namespace Tests\Feature;

use App\Contracts\QuoteNotifier;
use App\Models\Job;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Feature tests para la tarea 8.5: aceptación y rechazo de Cotizaciones
 * (Requisitos 5.7, 5.8, 6.4).
 *
 *  - Aceptar: la Cotización pasa a "aceptada", las demás de la misma Solicitud
 *    a "no_seleccionada" y se crea un Trabajo (Requisito 5.7).
 *  - Rechazar: la Cotización pasa a "rechazada" y se notifica al Prestador
 *    (Requisito 5.8).
 *  - Una Cotización "expirada" no puede aceptarse ni rechazarse (409, Req 6.4).
 *  - Solo el Cliente receptor puede aceptar/rechazar (403 en otro caso).
 */
class QuoteAcceptRejectTest extends TestCase
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

    private function provider(): User
    {
        $user = User::factory()->create();
        $user->assignRole('cliente');
        $user->assignRole('prestador');

        return $user;
    }

    private function openRequest(User $owner): QuoteRequest
    {
        return QuoteRequest::create([
            'client_user_id' => $owner->id,
            'service_category_id' => $this->category()->id,
            'title' => 'Instalación eléctrica',
            'description' => 'Instalar tomas en la cocina',
            'location' => 'Lima',
            'status' => QuoteRequest::STATUS_ABIERTA,
        ]);
    }

    private function makeQuote(
        QuoteRequest $request,
        User $provider,
        string $status = Quote::STATUS_PENDIENTE,
        int $deliveryDays = 10,
    ): Quote {
        return Quote::create([
            'provider_user_id' => $provider->id,
            'client_user_id' => $request->client_user_id,
            'quote_request_id' => $request->id,
            'type' => Quote::TYPE_PUBLICA,
            'service_description' => 'Servicio completo',
            'total_price' => 302.00,
            'delivery_days' => $deliveryDays,
            'expires_at' => now()->addDays(7),
            'status' => $status,
        ]);
    }

    // --- Requisito 5.7: aceptación -----------------------------------------

    public function test_aceptar_cotizacion_crea_trabajo_y_descarta_las_demas(): void
    {
        $client = $this->client();
        $request = $this->openRequest($client);

        $accepted = $this->makeQuote($request, $this->provider());
        $other = $this->makeQuote($request, $this->provider());

        Sanctum::actingAs($client);

        $response = $this->postJson("/api/v1/quotes/{$accepted->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.quote.status', Quote::STATUS_ACEPTADA);

        // Se devuelve el Trabajo creado.
        $jobId = $response->json('data.job.id');
        $this->assertNotNull($jobId);

        $this->assertDatabaseHas('quotes', [
            'id' => $accepted->id,
            'status' => Quote::STATUS_ACEPTADA,
        ]);
        // La otra Cotización de la misma Solicitud queda "no_seleccionada".
        $this->assertDatabaseHas('quotes', [
            'id' => $other->id,
            'status' => Quote::STATUS_NO_SELECCIONADA,
        ]);

        // Se creó exactamente un Trabajo, asociado a la Cotización aceptada.
        $this->assertDatabaseCount('service_jobs', 1);
        $this->assertDatabaseHas('service_jobs', [
            'id' => $jobId,
            'quote_id' => $accepted->id,
            'client_user_id' => $client->id,
            'provider_user_id' => $accepted->provider_user_id,
            'status' => Job::STATUS_EN_PROGRESO,
        ]);
    }

    public function test_aceptar_no_toca_cotizaciones_en_estado_terminal(): void
    {
        $client = $this->client();
        $request = $this->openRequest($client);

        $accepted = $this->makeQuote($request, $this->provider());
        $rejected = $this->makeQuote($request, $this->provider(), Quote::STATUS_RECHAZADA);
        $expired = $this->makeQuote($request, $this->provider(), Quote::STATUS_EXPIRADA);

        Sanctum::actingAs($client);

        $this->postJson("/api/v1/quotes/{$accepted->id}/accept")->assertOk();

        // Las que ya estaban en estado terminal se respetan.
        $this->assertDatabaseHas('quotes', ['id' => $rejected->id, 'status' => Quote::STATUS_RECHAZADA]);
        $this->assertDatabaseHas('quotes', ['id' => $expired->id, 'status' => Quote::STATUS_EXPIRADA]);
    }

    // --- Requisito 5.8: rechazo + notificación -----------------------------

    public function test_rechazar_cotizacion_marca_rechazada_y_notifica_al_prestador(): void
    {
        $client = $this->client();
        $request = $this->openRequest($client);
        $quote = $this->makeQuote($request, $this->provider());

        $notifier = Mockery::mock(QuoteNotifier::class);
        $notifier->shouldReceive('notifyQuoteRejected')
            ->once()
            ->with(Mockery::type(Quote::class));
        $this->app->instance(QuoteNotifier::class, $notifier);

        Sanctum::actingAs($client);

        $this->postJson("/api/v1/quotes/{$quote->id}/reject")
            ->assertOk()
            ->assertJsonPath('data.quote.status', Quote::STATUS_RECHAZADA);

        $this->assertDatabaseHas('quotes', [
            'id' => $quote->id,
            'status' => Quote::STATUS_RECHAZADA,
        ]);
        // Rechazar no crea Trabajo.
        $this->assertDatabaseCount('service_jobs', 0);
    }

    // --- Requisito 6.4: cotización expirada --------------------------------

    public function test_no_puede_aceptar_cotizacion_expirada(): void
    {
        $client = $this->client();
        $request = $this->openRequest($client);
        $quote = $this->makeQuote($request, $this->provider(), Quote::STATUS_EXPIRADA);

        Sanctum::actingAs($client);

        $this->postJson("/api/v1/quotes/{$quote->id}/accept")
            ->assertStatus(409);

        $this->assertDatabaseHas('quotes', ['id' => $quote->id, 'status' => Quote::STATUS_EXPIRADA]);
        $this->assertDatabaseCount('service_jobs', 0);
    }

    public function test_no_puede_rechazar_cotizacion_expirada(): void
    {
        $client = $this->client();
        $request = $this->openRequest($client);
        $quote = $this->makeQuote($request, $this->provider(), Quote::STATUS_EXPIRADA);

        Sanctum::actingAs($client);

        $this->postJson("/api/v1/quotes/{$quote->id}/reject")
            ->assertStatus(409);

        $this->assertDatabaseHas('quotes', ['id' => $quote->id, 'status' => Quote::STATUS_EXPIRADA]);
    }

    public function test_no_puede_aceptar_cotizacion_ya_aceptada(): void
    {
        $client = $this->client();
        $request = $this->openRequest($client);
        $quote = $this->makeQuote($request, $this->provider(), Quote::STATUS_ACEPTADA);

        Sanctum::actingAs($client);

        $this->postJson("/api/v1/quotes/{$quote->id}/accept")
            ->assertStatus(409);
    }

    // --- Autorización ------------------------------------------------------

    public function test_solo_el_cliente_receptor_puede_aceptar(): void
    {
        $client = $this->client();
        $request = $this->openRequest($client);
        $quote = $this->makeQuote($request, $this->provider());

        // Un tercero (otro cliente) intenta aceptar.
        Sanctum::actingAs($this->client());

        $this->postJson("/api/v1/quotes/{$quote->id}/accept")
            ->assertStatus(403);

        $this->assertDatabaseHas('quotes', ['id' => $quote->id, 'status' => Quote::STATUS_PENDIENTE]);
        $this->assertDatabaseCount('service_jobs', 0);
    }

    public function test_el_prestador_no_puede_aceptar_su_propia_cotizacion(): void
    {
        $client = $this->client();
        $request = $this->openRequest($client);
        $provider = $this->provider();
        $quote = $this->makeQuote($request, $provider);

        Sanctum::actingAs($provider);

        $this->postJson("/api/v1/quotes/{$quote->id}/accept")
            ->assertStatus(403);
    }

    public function test_solo_el_cliente_receptor_puede_rechazar(): void
    {
        $client = $this->client();
        $request = $this->openRequest($client);
        $quote = $this->makeQuote($request, $this->provider());

        Sanctum::actingAs($this->client());

        $this->postJson("/api/v1/quotes/{$quote->id}/reject")
            ->assertStatus(403);
    }

    public function test_aceptar_sin_autenticacion_retorna_401(): void
    {
        $client = $this->client();
        $request = $this->openRequest($client);
        $quote = $this->makeQuote($request, $this->provider());

        $this->postJson("/api/v1/quotes/{$quote->id}/accept")
            ->assertStatus(401);
    }

    public function test_rechazar_sin_autenticacion_retorna_401(): void
    {
        $client = $this->client();
        $request = $this->openRequest($client);
        $quote = $this->makeQuote($request, $this->provider());

        $this->postJson("/api/v1/quotes/{$quote->id}/reject")
            ->assertStatus(401);
    }

    public function test_aceptar_cotizacion_inexistente_retorna_404(): void
    {
        Sanctum::actingAs($this->client());

        $this->postJson('/api/v1/quotes/999999/accept')
            ->assertStatus(404);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
