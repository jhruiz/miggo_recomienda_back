<?php

namespace Tests\Feature;

use App\Contracts\QuoteNotifier;
use App\Jobs\ExpireDirectQuoteJob;
use App\Models\Quote;
use App\Models\ServiceCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tests para la tarea 11.1: vencimiento del plazo de respuesta de una
 * Cotización directa mediante ExpireDirectQuoteJob (Requisito 10.3).
 *
 * Cubre:
 *  - La transición "solicitada" -> "sin_respuesta" + notificación al Cliente
 *    cuando el Prestador no responde en 7 días.
 *  - El no-op cuando el Prestador ya respondió ("pendiente") o cuando la
 *    Cotización ya está en "sin_respuesta".
 *  - La guarda isFuture: no vence antes de alcanzar el plazo.
 */
class ExpireDirectQuoteJobTest extends TestCase
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
     * Crea una Cotización directa cuyo plazo de respuesta ya venció (expires_at
     * en el pasado), que es el escenario en el que el Job efectivamente actúa.
     */
    private function makeDueDirect(string $status = Quote::STATUS_SOLICITADA): Quote
    {
        return Quote::create([
            'provider_user_id' => $this->provider()->id,
            'client_user_id' => $this->client()->id,
            'quote_request_id' => null,
            'service_category_id' => $this->category()->id,
            'type' => Quote::TYPE_DIRECTA,
            'service_description' => 'Solicitud de cotización directa.',
            'total_price' => 0,
            'delivery_days' => 0,
            'expires_at' => now()->startOfDay()->subDay(),
            'status' => $status,
        ]);
    }

    // --- Requisito 10.3: solicitada -> sin_respuesta + notificar al Cliente -

    public function test_handle_marca_sin_respuesta_y_notifica_al_cliente(): void
    {
        $quote = $this->makeDueDirect();

        $notifier = Mockery::mock(QuoteNotifier::class);
        $notifier->shouldReceive('notifyClientOfDirectNoResponse')
            ->once()
            ->with(Mockery::on(fn (Quote $q): bool => $q->id === $quote->id
                && $q->status === Quote::STATUS_SIN_RESPUESTA));

        (new ExpireDirectQuoteJob($quote->id))->handle($notifier);

        $this->assertSame(Quote::STATUS_SIN_RESPUESTA, $quote->fresh()->status);
    }

    // --- No-op: el Prestador ya respondió (pendiente) ----------------------

    public function test_handle_no_modifica_cotizacion_ya_respondida(): void
    {
        $quote = $this->makeDueDirect(Quote::STATUS_PENDIENTE);

        $notifier = Mockery::mock(QuoteNotifier::class);
        $notifier->shouldNotReceive('notifyClientOfDirectNoResponse');

        (new ExpireDirectQuoteJob($quote->id))->handle($notifier);

        $this->assertSame(Quote::STATUS_PENDIENTE, $quote->fresh()->status);
    }

    // --- No-op: ya está en sin_respuesta -----------------------------------

    public function test_handle_no_renotifica_cotizacion_ya_sin_respuesta(): void
    {
        $quote = $this->makeDueDirect(Quote::STATUS_SIN_RESPUESTA);

        $notifier = Mockery::mock(QuoteNotifier::class);
        $notifier->shouldNotReceive('notifyClientOfDirectNoResponse');

        (new ExpireDirectQuoteJob($quote->id))->handle($notifier);

        $this->assertSame(Quote::STATUS_SIN_RESPUESTA, $quote->fresh()->status);
    }

    // --- Guarda isFuture: no vence antes del plazo -------------------------

    public function test_handle_no_vence_antes_de_alcanzar_el_plazo(): void
    {
        $quote = Quote::create([
            'provider_user_id' => $this->provider()->id,
            'client_user_id' => $this->client()->id,
            'quote_request_id' => null,
            'service_category_id' => $this->category()->id,
            'type' => Quote::TYPE_DIRECTA,
            'service_description' => 'Solicitud de cotización directa.',
            'total_price' => 0,
            'delivery_days' => 0,
            'expires_at' => now()->startOfDay()->addDays(7),
            'status' => Quote::STATUS_SOLICITADA,
        ]);

        $notifier = Mockery::mock(QuoteNotifier::class);
        $notifier->shouldNotReceive('notifyClientOfDirectNoResponse');

        (new ExpireDirectQuoteJob($quote->id))->handle($notifier);

        $this->assertSame(Quote::STATUS_SOLICITADA, $quote->fresh()->status);
    }

    public function test_handle_ignora_cotizacion_inexistente(): void
    {
        $notifier = Mockery::mock(QuoteNotifier::class);
        $notifier->shouldNotReceive('notifyClientOfDirectNoResponse');

        (new ExpireDirectQuoteJob(999999))->handle($notifier);

        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
