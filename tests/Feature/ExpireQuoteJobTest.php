<?php

namespace Tests\Feature;

use App\Contracts\QuoteNotifier;
use App\Jobs\ExpireQuoteJob;
use App\Models\Quote;
use App\Models\QuoteRequest;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tests para la tarea 8.8: expiración automática de Cotizaciones mediante
 * ExpireQuoteJob (Requisitos 6.2, 6.3, 6.6).
 *
 * Cubre:
 *  - Que al crear una Cotización se despacha ExpireQuoteJob con delay hasta
 *    `expires_at` (Requisitos 6.2, 6.3; decisión técnica 1 del diseño).
 *  - La transición "pendiente" -> "expirada" + notificación a ambas partes al
 *    ejecutar el Job (Requisitos 6.2, 6.3).
 *  - El no-op sobre Cotizaciones en estado terminal (aceptada/rechazada) y en
 *    estado ya expirada (Requisito 6.6).
 */
class ExpireQuoteJobTest extends TestCase
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

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(QuoteRequest $request, array $overrides = []): array
    {
        return array_merge([
            'quote_request_id' => $request->id,
            'service_description' => 'Instalación eléctrica completa.',
            'items' => [
                ['description' => 'Mano de obra', 'unit_price' => 100.00, 'quantity' => 1],
            ],
            'delivery_days' => 10,
            'expiration_days' => 7,
        ], $overrides);
    }

    /**
     * Crea una Cotización con la fecha de expiración ya alcanzada (en el
     * pasado), que es el escenario en el que ExpireQuoteJob se ejecuta.
     */
    private function makeExpiredDueQuote(string $status = Quote::STATUS_PENDIENTE): Quote
    {
        $provider = $this->provider();
        $owner = $this->client();
        $request = $this->openRequest($owner);

        return Quote::create([
            'provider_user_id' => $provider->id,
            'client_user_id' => $owner->id,
            'quote_request_id' => $request->id,
            'type' => Quote::TYPE_PUBLICA,
            'service_description' => 'Servicio',
            'total_price' => 100.00,
            'delivery_days' => 10,
            'expires_at' => now()->startOfDay()->subDay(),
            'status' => $status,
        ]);
    }

    // --- Requisitos 6.2/6.3: despacho con delay hasta expires_at -----------

    public function test_crear_cotizacion_despacha_expire_job_con_delay_hasta_expires_at(): void
    {
        Queue::fake();

        $provider = $this->provider();
        Sanctum::actingAs($provider);
        $owner = $this->client();
        $request = $this->openRequest($owner);

        app(QuoteService::class)->createQuote($provider, $this->validPayload($request, [
            'expiration_days' => 7,
        ]));

        $expectedDelay = now()->startOfDay()->addDays(7);

        Queue::assertPushed(ExpireQuoteJob::class, function (ExpireQuoteJob $job) use ($expectedDelay): bool {
            $quote = Quote::firstOrFail();

            return $job->quoteId === $quote->id
                && $job->delay !== null
                && $expectedDelay->equalTo($job->delay);
        });
    }

    // --- Requisitos 6.2/6.3: handle() expira y notifica a ambas partes -----

    public function test_handle_expira_cotizacion_pendiente_y_notifica_a_ambas_partes(): void
    {
        $quote = $this->makeExpiredDueQuote();

        $notifier = Mockery::mock(QuoteNotifier::class);
        $notifier->shouldReceive('notifyQuoteExpired')
            ->once()
            ->with(Mockery::on(fn (Quote $q): bool => $q->id === $quote->id
                && $q->status === Quote::STATUS_EXPIRADA));

        (new ExpireQuoteJob($quote->id))->handle($notifier);

        $this->assertSame(Quote::STATUS_EXPIRADA, $quote->fresh()->status);
    }

    // --- Requisito 6.6: no-op sobre estados terminales ---------------------

    public function test_handle_no_modifica_cotizacion_aceptada(): void
    {
        $quote = $this->makeExpiredDueQuote(Quote::STATUS_ACEPTADA);

        $notifier = Mockery::mock(QuoteNotifier::class);
        $notifier->shouldNotReceive('notifyQuoteExpired');

        (new ExpireQuoteJob($quote->id))->handle($notifier);

        $this->assertSame(Quote::STATUS_ACEPTADA, $quote->fresh()->status);
    }

    public function test_handle_no_modifica_cotizacion_rechazada(): void
    {
        $quote = $this->makeExpiredDueQuote(Quote::STATUS_RECHAZADA);

        $notifier = Mockery::mock(QuoteNotifier::class);
        $notifier->shouldNotReceive('notifyQuoteExpired');

        (new ExpireQuoteJob($quote->id))->handle($notifier);

        $this->assertSame(Quote::STATUS_RECHAZADA, $quote->fresh()->status);
    }

    public function test_handle_no_renotifica_cotizacion_ya_expirada(): void
    {
        $quote = $this->makeExpiredDueQuote(Quote::STATUS_EXPIRADA);

        $notifier = Mockery::mock(QuoteNotifier::class);
        $notifier->shouldNotReceive('notifyQuoteExpired');

        (new ExpireQuoteJob($quote->id))->handle($notifier);

        $this->assertSame(Quote::STATUS_EXPIRADA, $quote->fresh()->status);
    }

    // --- Requisito 6.2: no expira antes de alcanzar expires_at -------------

    public function test_handle_no_expira_cotizacion_cuya_fecha_no_ha_llegado(): void
    {
        $provider = $this->provider();
        $owner = $this->client();
        $request = $this->openRequest($owner);

        $quote = Quote::create([
            'provider_user_id' => $provider->id,
            'client_user_id' => $owner->id,
            'quote_request_id' => $request->id,
            'type' => Quote::TYPE_PUBLICA,
            'service_description' => 'Servicio',
            'total_price' => 100.00,
            'delivery_days' => 10,
            'expires_at' => now()->startOfDay()->addDays(7),
            'status' => Quote::STATUS_PENDIENTE,
        ]);

        $notifier = Mockery::mock(QuoteNotifier::class);
        $notifier->shouldNotReceive('notifyQuoteExpired');

        (new ExpireQuoteJob($quote->id))->handle($notifier);

        $this->assertSame(Quote::STATUS_PENDIENTE, $quote->fresh()->status);
    }

    public function test_handle_ignora_cotizacion_inexistente(): void
    {
        $notifier = Mockery::mock(QuoteNotifier::class);
        $notifier->shouldNotReceive('notifyQuoteExpired');

        (new ExpireQuoteJob(999999))->handle($notifier);

        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
